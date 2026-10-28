<?php
declare(strict_types=1);

namespace InvoiceChat;

use PDO;

class InvoiceManager
{
    public function __construct(private PDO $db) {}

    // ── Create a new draft invoice or quote ───────────────────────
    public function create(int $userId, array $defaults = []): int
    {
        $docType = ($defaults['doc_type'] ?? 'invoice') === 'quote' ? 'quote' : 'invoice';
        $number  = $this->nextInvoiceNumber($userId, $docType);
        $stmt    = $this->db->prepare(
            'INSERT INTO invoices
                (user_id, doc_type, invoice_number, issue_date, due_date, currency, tax_rate, discount_percent, template_name)
             VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $docType,
            $number,
            $defaults['due_date']         ?? date('Y-m-d', strtotime('+30 days')),
            $defaults['currency']         ?? 'ZAR',
            $defaults['tax_rate']         ?? 0,
            $defaults['discount_percent'] ?? 0,
            $defaults['template']         ?? 'classic',
        ]);
        return (int) $this->db->lastInsertId();
    }

    // ── Fetch single invoice ──────────────────────────────────────
    public function get(int $invoiceId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM invoices WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$invoiceId, $userId]);
        return $stmt->fetch() ?: null;
    }

    // ── Update invoice fields (only non-null values) ──────────────
    public function update(int $invoiceId, int $userId, array $fields): void
    {
        if (empty($fields)) return;
        $allowed = [
            'client_name', 'client_email', 'client_phone',
            'issue_date', 'due_date', 'currency', 'tax_rate',
            'discount_percent', 'notes', 'template_name',
            'total_amount', 'status', 'pdf_path',
        ];
        $set  = [];
        $vals = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $set[]  = "`{$col}` = ?";
            $vals[] = $val;
        }
        if (empty($set)) return;
        // Paid documents remember when, for the dashboard and reports.
        if (($fields['status'] ?? null) === 'paid') {
            $set[] = '`paid_at` = COALESCE(`paid_at`, NOW())';
        } elseif (isset($fields['status'])) {
            $set[] = '`paid_at` = NULL';
        }
        $vals[] = $invoiceId;
        $vals[] = $userId;
        $stmt = $this->db->prepare(
            'UPDATE invoices SET ' . implode(', ', $set) . ' WHERE id = ? AND user_id = ?'
        );
        $stmt->execute($vals);
        if (isset($fields['status']) && $fields['status'] !== 'draft' && $stmt->rowCount()) {
            $this->db->prepare('INSERT INTO document_events (invoice_id, user_id, kind, detail) VALUES (?, ?, ?, ?)')
                ->execute([$invoiceId, $userId, $fields['status'] === 'paid' ? 'paid' : 'status', $fields['status']]);
        }
    }

    // ── Items ─────────────────────────────────────────────────────
    public function addItem(int $invoiceId, string $name, float $qty, float $price, string $desc = ''): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO invoice_items (invoice_id, product_name, quantity, unit_price, description)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$invoiceId, $name, $qty, $price, $desc]);
        return (int) $this->db->lastInsertId();
    }

    public function getItems(int $invoiceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public function deleteItem(int $itemId, int $invoiceId): void
    {
        $this->db->prepare(
            'DELETE FROM invoice_items WHERE id = ? AND invoice_id = ?'
        )->execute([$itemId, $invoiceId]);
    }

    public function updateItem(int $itemId, int $invoiceId, array $fields): void
    {
        $allowed = ['product_name', 'quantity', 'unit_price', 'description'];
        $set = $vals = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $set[]  = "`{$col}` = ?";
            $vals[] = $val;
        }
        if (empty($set)) return;
        $vals[] = $itemId;
        $vals[] = $invoiceId;
        $this->db->prepare(
            'UPDATE invoice_items SET ' . implode(', ', $set) . ' WHERE id = ? AND invoice_id = ?'
        )->execute($vals);
    }

    // ── Recalculate and persist total ─────────────────────────────
    public function recalcTotal(int $invoiceId, int $userId): float
    {
        $invoice = $this->get($invoiceId, $userId);
        $items   = $this->getItems($invoiceId);
        if (!$invoice || empty($items)) return 0.0;

        $subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
        $discount = $subtotal * ($invoice['discount_percent'] / 100);
        $taxable  = $subtotal - $discount;
        $total    = $taxable + $taxable * ($invoice['tax_rate'] / 100);

        $this->update($invoiceId, $userId, ['total_amount' => round($total, 2)]);
        return round($total, 2);
    }

    // ── List user invoices/quotes for sidebar ─────────────────────
    public function listForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, doc_type, invoice_number, client_name, status, total_amount, currency, created_at
             FROM invoices
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }

    // ── Convert an accepted quote into a new invoice ──────────────
    public function convertQuoteToInvoice(int $quoteId, int $userId): ?int
    {
        $quote = $this->get($quoteId, $userId);
        if (!$quote || $quote['doc_type'] !== 'quote') return null;

        $newId = $this->create($userId, [
            'doc_type'         => 'invoice',
            'currency'         => $quote['currency'],
            'tax_rate'         => $quote['tax_rate'],
            'discount_percent' => $quote['discount_percent'],
            'template'         => $quote['template_name'],
        ]);

        $this->update($newId, $userId, [
            'client_name'  => $quote['client_name'],
            'client_email' => $quote['client_email'],
            'client_phone' => $quote['client_phone'],
            'notes'        => $quote['notes'],
        ]);

        // Copy line items
        foreach ($this->getItems($quoteId) as $item) {
            $this->addItem(
                $newId,
                (string)$item['product_name'],
                (float)$item['quantity'],
                (float)$item['unit_price'],
                (string)($item['description'] ?? '')
            );
        }

        $this->recalcTotal($newId, $userId);
        return $newId;
    }

    // ── Delete invoice + cascade ──────────────────────────────────
    public function delete(int $invoiceId, int $userId): void
    {
        $invoice = $this->get($invoiceId, $userId);
        if ($invoice && ($pdf = pdfFile($invoice['pdf_path']))) {
            unlink($pdf);
        }
        $this->db->prepare(
            'DELETE FROM invoices WHERE id = ? AND user_id = ?'
        )->execute([$invoiceId, $userId]);
    }

    // ── Generate next document number (INV-/QUO-YYYY-NNNNN) ───────
    private function nextInvoiceNumber(int $userId, string $docType = 'invoice'): string
    {
        $prefix = $docType === 'quote' ? 'QUO' : 'INV';
        $year   = date('Y');
        $like   = "{$prefix}-{$year}-%";

        // Use the highest existing numeric suffix (not COUNT) so deletions
        // never cause a duplicate number to be reissued.
        $maxStmt = $this->db->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED))
             FROM invoices
             WHERE user_id = ? AND invoice_number LIKE ?"
        );
        $maxStmt->execute([$userId, $like]);
        $next = (int) $maxStmt->fetchColumn() + 1;

        // Defensive guard: skip any number that somehow already exists for
        // this user (e.g. legacy duplicate rows), so new docs stay unique.
        $existsStmt = $this->db->prepare(
            'SELECT 1 FROM invoices WHERE user_id = ? AND invoice_number = ? LIMIT 1'
        );
        do {
            $number = sprintf('%s-%s-%05d', $prefix, $year, $next);
            $existsStmt->execute([$userId, $number]);
            $taken = (bool) $existsStmt->fetchColumn();
            $next++;
        } while ($taken);

        return $number;
    }
}
