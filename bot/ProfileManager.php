<?php
declare(strict_types=1);

namespace InvoiceChat;

use PDO;

class ProfileManager
{
    public function __construct(private PDO $db) {}

    public function get(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public function isOnboarded(array $user): bool
    {
        return !empty($user['company_name'])
            && !empty($user['address'])
            && !empty($user['phone']);
    }

    public function update(int $userId, array $fields): void
    {
        $allowed = [
            'company_name', 'address', 'phone', 'website',
            'bank_details', 'tax_number', 'logo_path',
        ];
        $set  = [];
        $vals = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $set[]  = "`{$col}` = ?";
            $vals[] = $val;
        }
        if (empty($set)) return;
        $vals[] = $userId;
        $this->db->prepare(
            'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?'
        )->execute($vals);
    }

    public function getSettings(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT settings FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetchColumn();
        $defaults = [
            'currency'      => 'ZAR',
            'tax_rate'      => 0,
            'discount'      => 0,
            'template'      => 'classic',
            'payment_terms' => 'Due in 30 days',
            'accent_color'  => '#2563eb',
        ];
        if (!$row) return $defaults;
        return array_merge($defaults, json_decode($row, true) ?? []);
    }

    public function updateSettings(int $userId, array $patch): void
    {
        $current = $this->getSettings($userId);
        $merged  = array_merge($current, $patch);
        $this->db->prepare(
            'UPDATE users SET settings = ? WHERE id = ?'
        )->execute([json_encode($merged), $userId]);
    }
}
