<?php
declare(strict_types=1);

namespace InvoiceChat;

use PDO;

class BotEngine
{
    private InvoiceManager $im;
    private ProfileManager $pm;
    private array          $user;
    private array          $settings;

    public function __construct(private PDO $db, private int $userId)
    {
        $this->im       = new InvoiceManager($db);
        $this->pm       = new ProfileManager($db);
        $this->user     = $this->pm->get($userId) ?? [];
        $this->settings = $this->pm->getSettings($userId);
    }

    // ─────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────

    /**
     * Process a user message and return bot response messages.
     * $state, $data, $invoiceId are updated by reference.
     */
    public function process(
        string  $input,
        string  &$state,
        array   &$data,
        ?int    &$invoiceId
    ): array {
        $input = trim($input);

        // Global slash commands (work from any state)
        if (str_starts_with($input, '/')) {
            return $this->handleCommand($input, $state, $data, $invoiceId);
        }

        $method = 'state' . $this->toCamel($state);
        if (method_exists($this, $method)) {
            return $this->$method($input, $state, $data, $invoiceId);
        }
        return $this->stateIdle($input, $state, $data, $invoiceId);
    }

    /** Called once on page load / new session to get the opening message. */
    public function greet(string &$state): array
    {
        // Reload fresh user data
        $this->user = $this->pm->get($this->userId) ?? [];

        if (!$this->pm->isOnboarded($this->user)) {
            $state = 'onboard_name';
            return [
                $this->msg("Welcome to *InvoiceChat*! I'll help you create and send professional invoices in minutes."),
                $this->msg("Let's set up your profile first.\n\nWhat is your company or freelancer name?"),
            ];
        }

        $state = 'idle';
        $name  = $this->user['company_name'];
        return [
            $this->msg("Welcome back, *{$name}*! What would you like to do?"),
            $this->buttons('Choose an action — or just describe it, e.g. *Invoice Acme for 3 logos at R500 each*:', ['/new – Create invoice', '/quote – Create quote', '/ai – ✨ Describe it', '/settings – Update profile']),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Global commands
    // ─────────────────────────────────────────────────────────────

    private function handleCommand(string $cmd, string &$state, array &$data, ?int &$invoiceId): array
    {
        $base = strtolower(strtok($cmd, ' '));
        switch ($base) {
            case '/new':
            case '/invoice':
                return $this->startNewDocument('invoice', $state, $data, $invoiceId);
            case '/quote':
                return $this->startNewDocument('quote', $state, $data, $invoiceId);
            case '/settings':
                $state = 'settings_menu';
                return $this->showSettingsMenu();
            case '/help':
                return $this->showHelp($state);
            case '/history':
                return [$this->msg("Open the sidebar on the left to browse your past invoices and quotes.")];
            case '/paid':
                if ($invoiceId) {
                    $this->im->update($invoiceId, $this->userId, ['status' => 'paid']);
                    return [$this->msg("Invoice marked as *paid*. Congratulations!")];
                }
                return [$this->msg("No active invoice to mark as paid.")];
            case '/accept':
                if ($invoiceId) {
                    $doc = $this->im->get($invoiceId, $this->userId);
                    if ($doc && $doc['doc_type'] === 'quote') {
                        $this->im->update($invoiceId, $this->userId, ['status' => 'accepted']);
                        return [
                            $this->msg("Quote marked as *accepted*!"),
                            $this->buttons("Convert it into an invoice now?", ['Convert to Invoice', 'Done']),
                        ];
                    }
                }
                return [$this->msg("No active quote to accept.")];
            case '/convert':
                return $this->convertActiveQuote($state, $data, $invoiceId);
            case '/cancel':
                $state     = 'idle';
                $data      = [];
                $invoiceId = null;
                return [$this->msg("Cancelled. Type /new for an invoice, /quote for a quote, or /help for commands.")];
            default:
                return [$this->msg("Unknown command. Type /help to see available commands.")];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Onboarding states
    // ─────────────────────────────────────────────────────────────

    private function stateOnboardName(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strlen($input) < 2) return [$this->msg("Please enter a valid name (at least 2 characters).")];
        $this->pm->update($this->userId, ['company_name' => $input]);
        $state = 'onboard_address';
        return [$this->msg("Got it, *{$input}*! What is your business address?")];
    }

    private function stateOnboardAddress(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strlen($input) < 5) return [$this->msg("Please enter a full address.")];
        $this->pm->update($this->userId, ['address' => $input]);
        $state = 'onboard_phone';
        return [$this->msg("Address saved. What is your business phone number?")];
    }

    private function stateOnboardPhone(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (!preg_match('/^[+\d\s\-()]{6,20}$/', $input)) {
            return [$this->msg("Please enter a valid phone number (digits, +, spaces, dashes).")];
        }
        $this->pm->update($this->userId, ['phone' => $input]);
        $state = 'onboard_website';
        return [
            $this->msg("Phone saved."),
            $this->buttons("Do you have a website? (optional)", ['Skip']),
        ];
    }

    private function stateOnboardWebsite(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $this->pm->update($this->userId, ['website' => $input]);
        }
        $state = 'onboard_bank_holder';
        $data['bank'] = ['return_state' => 'onboard_tax'];
        return [
            $this->buttons(
                "Let's add your payment/banking details (shown on invoices) — optional.\n\nWhat is the *Account Holder* name?",
                ['Skip']
            ),
        ];
    }

    // ── Bank details — multi-step collection ─────────────────────────

    private function stateOnboardBankHolder(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $data['bank']['holder'] = $input;
        }
        $state = 'onboard_bank_account';
        return [$this->buttons("What is the *Account Number*?", ['Skip'])];
    }

    private function stateOnboardBankAccount(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $data['bank']['account'] = $input;
        }
        $state = 'onboard_bank_name';
        return [$this->buttons("Which bank do you use?", [
            'ABSA Bank', 'African Bank', 'Bank Zero', 'Bidvest Bank',
            'Capitec Bank', 'Discovery Bank', 'FNB', 'Investec Bank',
            'Nedbank', 'Sasfin Bank', 'Standard Bank', 'TymeBank', 'Skip',
        ])];
    }

    private function stateOnboardBankName(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        static $bankMap = [
            'absa'          => ['ABSA Bank',                 '632005'],
            'african'       => ['African Bank',              '430000'],
            'bank zero'     => ['Bank Zero',                 '888000'],
            'bidvest'       => ['Bidvest Bank',              '462005'],
            'capitec'       => ['Capitec Bank',              '470010'],
            'discovery'     => ['Discovery Bank',            '679000'],
            'fnb'           => ['First National Bank (FNB)', '250655'],
            'first national'=> ['First National Bank (FNB)', '250655'],
            'investec'      => ['Investec Bank',             '580105'],
            'nedbank'       => ['Nedbank',                   '198765'],
            'sasfin'        => ['Sasfin Bank',               '683000'],
            'standard'      => ['Standard Bank',             '051001'],
            'tyme'          => ['TymeBank',                  '678910'],
        ];

        $lower = strtolower($input);
        if ($lower !== 'skip' && $input !== '') {
            $matched = false;
            foreach ($bankMap as $key => [$name, $branch]) {
                if (str_contains($lower, $key)) {
                    $data['bank']['bank_name'] = $name;
                    $data['bank']['branch']    = $branch;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $data['bank']['bank_name'] = $input;
            }
        }

        $state = 'onboard_bank_branch';
        if (!empty($data['bank']['branch'])) {
            $bName   = $data['bank']['bank_name'] ?? 'your bank';
            $bBranch = $data['bank']['branch'];
            return [$this->buttons(
                "Branch code for *{$bName}* is *{$bBranch}*.\nConfirm, or type a different code.",
                ['Confirm', 'Skip']
            )];
        }
        return [$this->buttons("What is the *Branch Code*?", ['Skip'])];
    }

    private function stateOnboardBankBranch(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower(trim($input));
        if ($lower !== 'confirm' && $lower !== 'skip' && $input !== '') {
            if (preg_match('/^\d{5,7}$/', $input)) {
                $data['bank']['branch'] = $input;
            }
        }

        // Build and save formatted bank_details
        $b     = $data['bank'] ?? [];
        $parts = [];
        if (!empty($b['holder']))    $parts[] = "Account Holder: {$b['holder']}";
        if (!empty($b['account']))   $parts[] = "Account Number: {$b['account']}";
        if (!empty($b['bank_name'])) $parts[] = "Bank Name: {$b['bank_name']}";
        if (!empty($b['branch']))    $parts[] = "Branch Code: {$b['branch']}";
        if (!empty($parts)) {
            $this->pm->update($this->userId, ['bank_details' => implode("\n", $parts)]);
        }

        $returnState = $b['return_state'] ?? 'idle';
        unset($data['bank']);

        if ($returnState === 'onboard_tax') {
            $state = 'onboard_tax';
            return [$this->buttons(
                "Payment details saved!\n\nWhat is your Tax or VAT number? (optional)",
                ['Skip']
            )];
        }
        $state = 'idle';
        return [$this->msg("Payment details updated!")];
    }

    private function stateOnboardTax(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $this->pm->update($this->userId, ['tax_number' => $input]);
        }
        $state = 'onboard_logo';
        return [
            $this->upload("Would you like to upload your company logo? It will appear on your invoices. Click the upload button or type *skip*.

No logo yet? Make one free in a minute: " . APP_URL . "/logo.php"),
        ];
    }

    private function stateOnboardLogo(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        // Logo upload is handled by upload_logo.php; 'uploaded' is sent by JS on success
        $state = 'onboard_color';
        return [
            $this->buttons(
                "Last step — pick an *accent colour* for your invoices & quotes (or type a hex like #2563eb):",
                $this->colorChoices()
            ),
        ];
    }

    private function stateOnboardColor(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $hex = $this->parseColor($input);
        if ($hex === null) {
            return [$this->buttons("Please pick a colour or type a hex code like *#2563eb*:", $this->colorChoices())];
        }
        $this->pm->updateSettings($this->userId, ['accent_color' => $hex]);
        $state = 'idle';
        $this->user = $this->pm->get($this->userId) ?? [];
        return [
            $this->msg("Accent colour set! Your documents will use *{$hex}*."),
            $this->msg("Profile setup complete!"),
            $this->buttons(
                "You're all set, *{$this->user['company_name']}*! What would you like to do?",
                ['/new – Create invoice', '/quote – Create quote', '/settings – Update profile', '/help – Show commands']
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Idle state
    // ─────────────────────────────────────────────────────────────

    private function stateIdle(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        return [
            $this->msg("I didn't understand that. Here's what you can do:"),
            $this->buttons('Choose an action:', ['/new – Create invoice', '/quote – Create quote', '/ai – ✨ Describe it', '/help – Commands']),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // New invoice flow
    // ─────────────────────────────────────────────────────────────

    private function startNewDocument(string $docType, string &$state, array &$data, ?int &$invoiceId): array
    {
        $docType = $docType === 'quote' ? 'quote' : 'invoice';
        $s   = $this->settings;
        $id  = $this->im->create($this->userId, [
            'doc_type'         => $docType,
            'currency'         => $s['currency'],
            'tax_rate'         => $s['tax_rate'],
            'discount_percent' => $s['discount'],
            'template'         => $s['template'],
        ]);
        $invoiceId = $id;
        $state     = 'invoice_client';
        $data      = ['doc_type' => $docType];
        $label     = $docType === 'quote' ? 'quote' : 'invoice';
        return [$this->msg("New {$label} started! What is the *client's name*?")];
    }

    /** Convert the currently active quote into a new invoice. */
    private function convertActiveQuote(string &$state, array &$data, ?int &$invoiceId): array
    {
        if (!$invoiceId) {
            return [$this->msg("No active quote to convert. Open a quote from the sidebar first.")];
        }
        $doc = $this->im->get($invoiceId, $this->userId);
        if (!$doc || $doc['doc_type'] !== 'quote') {
            return [$this->msg("The active document isn't a quote.")];
        }
        $newId = $this->im->convertQuoteToInvoice($invoiceId, $this->userId);
        if (!$newId) {
            return [$this->msg("Couldn't convert that quote. Please try again.")];
        }
        $invoiceId = $newId;
        $state     = 'invoice_send';
        $data      = ['doc_type' => 'invoice'];
        $newDoc    = $this->im->get($newId, $this->userId);
        return [
            $this->msg("Quote converted to invoice *{$newDoc['invoice_number']}*!"),
            ['type' => 'generate_pdf', 'invoice_id' => $newId],
        ];
    }

    private function stateInvoiceClient(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strlen($input) < 2) return [$this->msg("Please enter the client's name.")];
        $this->im->update($invoiceId, $this->userId, ['client_name' => $input]);
        $state = 'invoice_client_email';
        return [
            $this->buttons("Client email address? (for sending the invoice)", ['Skip']),
        ];
    }

    private function stateInvoiceClientEmail(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
                return [$this->msg("That doesn't look like a valid email. Try again or type *skip*.")];
            }
            $this->im->update($invoiceId, $this->userId, ['client_email' => $input]);
        }
        $state = 'invoice_client_phone';
        return [
            $this->buttons("Client phone number? (for WhatsApp sending, include country code)", ['Skip']),
        ];
    }

    private function stateInvoiceClientPhone(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $this->im->update($invoiceId, $this->userId, ['client_phone' => $input]);
        }
        $state = 'product_name';
        return [$this->msg("Let's add your products/services.\n\nWhat is the *product or service name*?")];
    }

    // ─────────────────────────────────────────────────────────────
    // Product collection
    // ─────────────────────────────────────────────────────────────

    private function stateProductName(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strlen($input) < 1) return [$this->msg("Please enter a product or service name.")];
        $data['product'] = ['name' => $input];
        $state = 'product_qty';
        return [$this->msg("Quantity for *{$input}*?")];
    }

    private function stateProductQty(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (!is_numeric($input) || (float)$input <= 0) {
            return [$this->msg("Please enter a valid quantity (e.g. 1, 2.5).")];
        }
        $data['product']['qty'] = (float)$input;
        $state = 'product_price';
        return [$this->msg("Unit price for *{$data['product']['name']}*? (numbers only, e.g. 150.00)")];
    }

    private function stateProductPrice(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        // Strip currency symbols, spaces, commas (e.g. "R 1,500.00" → "1500.00")
        $cleaned = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $input));
        $price   = $cleaned !== '' ? $cleaned : null;
        if ($price === null || !is_numeric($price) || (float)$price < 0) {
            return [$this->msg("Please enter a valid price (e.g. 150.00 or 1500). Numbers only.")];
        }
        $data['product']['price'] = (float)$price;
        $state = 'product_desc';
        return [
            $this->buttons("Add a short description for this item? (optional)", ['Skip']),
        ];
    }

    private function stateProductDesc(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if ($invoiceId === null) {
            $state = 'idle';
            return [$this->msg("Session expired. Type /new to start a fresh invoice.")];
        }
        $desc = (strtolower($input) === 'skip') ? '' : $input;
        $p    = $data['product'] ?? [];
        if (empty($p['name']) || !isset($p['qty'], $p['price'])) {
            $state = 'product_name';
            return [$this->msg("Something went wrong with that item. Let's try again — what is the product or service name?")];
        }
        $this->im->addItem($invoiceId, (string)$p['name'], (float)$p['qty'], (float)$p['price'], $desc);
        $data['product'] = [];

        $items   = $this->im->getItems($invoiceId);
        $invoice = $this->im->get($invoiceId, $this->userId);
        $summary = $this->buildItemsList($items, $invoice['currency'] ?? 'USD');

        $state = 'product_more';
        return [
            $this->msg("Item added!\n\n{$summary}"),
            $this->buttons("Add another item or continue?", ['Add another item', 'Done – continue']),
        ];
    }

    private function stateProductMore(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower($input);
        if (str_contains($lower, 'add') || str_contains($lower, 'another')) {
            $state = 'product_name';
            return [$this->msg("What is the next product or service name?")];
        }

        // Continue to invoice details
        $invoice = $this->im->get($invoiceId, $this->userId);
        $state   = 'invoice_tax';
        return [
            $this->msg(
                "Tax rate %? Current default: *{$this->settings['tax_rate']}%*\n" .
                "(Type a number like 20 for 20%, or 0 for no tax, or press Enter / type *keep* to use the default)"
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Invoice detail states
    // ─────────────────────────────────────────────────────────────

    private function stateInvoiceTax(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if ($input === '' || strtolower($input) === 'keep') {
            $tax = (float)$this->settings['tax_rate'];
        } elseif (is_numeric($input) && (float)$input >= 0 && (float)$input <= 100) {
            $tax = (float)$input;
        } else {
            return [$this->msg("Please enter a valid tax rate between 0 and 100.")];
        }
        $this->im->update($invoiceId, $this->userId, ['tax_rate' => $tax]);
        $state = 'invoice_discount';
        return [
            $this->msg(
                "Discount %? Current default: *{$this->settings['discount']}%*\n" .
                "(Type a number like 5 for 5%, 0 for no discount, or *keep*)"
            ),
        ];
    }

    private function stateInvoiceDiscount(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if ($input === '' || strtolower($input) === 'keep') {
            $disc = (float)$this->settings['discount'];
        } elseif (is_numeric($input) && (float)$input >= 0 && (float)$input <= 100) {
            $disc = (float)$input;
        } else {
            return [$this->msg("Please enter a valid discount between 0 and 100.")];
        }
        $this->im->update($invoiceId, $this->userId, ['discount_percent' => $disc]);
        $state = 'invoice_currency';
        return [
            $this->buttons(
                "Currency? Default: *{$this->settings['currency']}* (type a code or *keep*)",
                ['ZAR', 'USD', 'EUR', 'GBP', 'Keep']
            ),
        ];
    }

    private function stateInvoiceCurrency(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if ($input === '' || strtolower($input) === 'keep') {
            $cur = $this->settings['currency'];
        } else {
            $cur = strtoupper(trim($input));
            if (!preg_match('/^[A-Z]{3}$/', $cur)) {
                return [$this->msg("Please enter a valid 3-letter currency code (e.g. ZAR, USD, EUR).")];
            }
        }
        $this->im->update($invoiceId, $this->userId, ['currency' => $cur]);
        $state   = 'invoice_due_date';
        $default = date('Y-m-d', strtotime('+30 days'));
        $label   = $this->docType($data, $invoiceId) === 'quote' ? 'Valid until' : 'Due date';
        return [
            $this->msg("{$label}? (format: YYYY-MM-DD, default: *{$default}*, or type *keep*)"),
        ];
    }

    private function stateInvoiceDueDate(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if ($input === '' || in_array(strtolower($input), ['keep', 'skip', 'default'], true)) {
            $date = date('Y-m-d', strtotime('+30 days'));
        } else {
            $d = \DateTime::createFromFormat('Y-m-d', $input);
            if (!$d || $d->format('Y-m-d') !== $input) {
                return [$this->msg("Please enter a date in YYYY-MM-DD format (e.g. " . date('Y-m-d', strtotime('+30 days')) . ").")];
            }
            $date = $input;
        }
        $this->im->update($invoiceId, $this->userId, ['due_date' => $date]);
        $state = 'invoice_template';
        return [
            $this->buttons(
                "Choose a template:",
                [
                    'Classic – formal & clean',
                    'Modern – minimal & sleek',
                    'Freelancer – compact',
                    'Corporate – bold grid',
                    'Banded – colour highlight',
                ]
            ),
        ];
    }

    private function stateInvoiceTemplate(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $this->im->update($invoiceId, $this->userId, ['template_name' => $this->parseTemplate($input)]);
        $state = 'invoice_notes';
        return [
            $this->buttons("Any additional notes or payment terms to add to the document? (optional)", ['Skip']),
        ];
    }

    /** Map free text / button label to a template name. */
    private function parseTemplate(string $input): string
    {
        $lower = strtolower($input);
        foreach (['modern', 'freelancer', 'corporate', 'banded'] as $tpl) {
            if (str_contains($lower, $tpl)) return $tpl;
        }
        return 'classic';
    }

    private function stateInvoiceNotes(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (strtolower($input) !== 'skip' && $input !== '') {
            $this->im->update($invoiceId, $this->userId, ['notes' => $input]);
        }
        $state   = 'invoice_confirm';
        $invoice = $this->im->get($invoiceId, $this->userId);
        $items   = $this->im->getItems($invoiceId);
        $this->im->recalcTotal($invoiceId, $this->userId);
        $invoice = $this->im->get($invoiceId, $this->userId); // refresh total

        require_once dirname(__DIR__) . '/includes/functions.php';
        $summary = buildInvoiceSummary($invoice, $items, $invoice['currency']);

        return [
            $this->msg($summary),
            $this->buttons("Ready to generate the PDF?", ['Generate PDF', 'Cancel']),
        ];
    }

    private function stateInvoiceConfirm(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower($input);
        if (str_contains($lower, 'cancel')) {
            $state = 'idle';
            return [$this->msg("Cancelled. Your draft is still saved. Type /new to start fresh.")];
        }
        if (!str_contains($lower, 'generate') && !str_contains($lower, 'yes') && !str_contains($lower, 'pdf')) {
            return [$this->buttons("Please choose an option:", ['Generate PDF', 'Cancel'])];
        }

        $state = 'invoice_send';
        return [
            ['type' => 'generate_pdf', 'invoice_id' => $invoiceId],
        ];
    }

    private function stateInvoiceSend(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower($input);
        if (str_contains($lower, 'email')) {
            $invoice = $this->im->get($invoiceId, $this->userId);
            $email   = $invoice['client_email'] ?? '';
            $state   = 'send_email';
            if ($email) {
                return [$this->buttons("Send to *{$email}*?", ['Yes, send', 'Different email'])];
            }
            return [$this->msg("What is the client's email address?")];
        }
        if (str_contains($lower, 'whatsapp')) {
            $invoice = $this->im->get($invoiceId, $this->userId);
            $phone   = $invoice['client_phone'] ?? '';
            $state   = 'send_whatsapp';
            if ($phone) {
                return [$this->buttons("Send WhatsApp to *{$phone}*?", ['Yes, send', 'Different number'])];
            }
            return [$this->msg("What is the client's WhatsApp number (with country code, e.g. +1234567890)?")];
        }
        if (str_contains($lower, 'convert')) {
            return $this->convertActiveQuote($state, $data, $invoiceId);
        }
        if (str_contains($lower, 'accept')) {
            $this->im->update($invoiceId, $this->userId, ['status' => 'accepted']);
            return [
                $this->msg("Quote marked as *accepted*!"),
                $this->buttons("Convert it into an invoice now?", ['Convert to Invoice', 'Done']),
            ];
        }
        if (str_contains($lower, 'paid')) {
            $this->im->update($invoiceId, $this->userId, ['status' => 'paid']);
            return [$this->msg("Invoice marked as *paid*. Congratulations!")];
        }
        if (str_contains($lower, 'done') || str_contains($lower, 'close')) {
            $state = 'idle';
            $isQuote = $this->docType($data, $invoiceId) === 'quote';
            $word    = $isQuote ? 'Quote' : 'Invoice';
            return [$this->msg("{$word} saved. Check the sidebar for your history.")];
        }

        // Default — show options
        $isQuote = $this->docType($data, $invoiceId) === 'quote';
        $extra   = $isQuote
            ? ['Send by Email', 'Send via WhatsApp', 'Accept quote', 'Done']
            : ['Send by Email', 'Send via WhatsApp', 'Done'];
        $word    = $isQuote ? 'Quote' : 'Invoice';
        return [
            $this->buttons(
                "{$word} PDF is ready! What would you like to do?",
                $extra
            ),
        ];
    }

    private function stateSendEmail(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower($input);
        $invoice = $this->im->get($invoiceId, $this->userId);

        if ($lower === 'yes, send' && $invoice['client_email']) {
            $state = 'invoice_send';
            return [['type' => 'send_email', 'invoice_id' => $invoiceId, 'email' => $invoice['client_email']]];
        }

        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $state = 'invoice_send';
            return [['type' => 'send_email', 'invoice_id' => $invoiceId, 'email' => $input]];
        }

        if ($lower === 'different email') {
            return [$this->msg("Enter the email address to send to:")];
        }

        return [$this->msg("Please enter a valid email address.")];
    }

    private function stateSendWhatsapp(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower   = strtolower($input);
        $invoice = $this->im->get($invoiceId, $this->userId);

        if ($lower === 'yes, send' && $invoice['client_phone']) {
            $state = 'invoice_send';
            return [['type' => 'send_whatsapp', 'invoice_id' => $invoiceId, 'phone' => $invoice['client_phone']]];
        }

        if (preg_match('/^\+?[\d\s\-]{7,20}$/', $input)) {
            $state = 'invoice_send';
            return [['type' => 'send_whatsapp', 'invoice_id' => $invoiceId, 'phone' => $input]];
        }

        if ($lower === 'different number') {
            return [$this->msg("Enter the WhatsApp number (with country code, e.g. +1234567890):")];
        }

        return [$this->msg("Please enter a valid phone number including country code (e.g. +1234567890).")];
    }

    // ─────────────────────────────────────────────────────────────
    // Settings
    // ─────────────────────────────────────────────────────────────

    private function stateSettingsMenu(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower($input);

        // Bank requires multi-step flow
        if (str_contains($lower, 'bank') || str_contains($lower, 'payment')) {
            $state = 'onboard_bank_holder';
            $data['bank'] = ['return_state' => 'idle'];
            return [$this->buttons(
                "Let's update your payment/banking details.\n\nWhat is the *Account Holder* name?",
                ['Skip']
            )];
        }

        $map = [
            'currency'  => ['settings_currency',  "Enter new default currency (e.g. ZAR, USD, EUR):"],
            'tax'       => ['settings_tax',        "Enter new default tax rate % (e.g. 15):"],
            'discount'  => ['settings_discount',   "Enter new default discount % (e.g. 5):"],
            'template'  => ['settings_template',   "Choose default template:"],
            'color'     => ['settings_color',      "Pick an accent colour:"],
            'colour'    => ['settings_color',      "Pick an accent colour:"],
            'terms'     => ['settings_terms',      "Enter default payment terms (e.g. 'Due in 14 days'):"],
            'company'   => ['onboard_name',        "Enter new company/freelancer name:"],
            'address'   => ['onboard_address',     "Enter new address:"],
            'phone'     => ['onboard_phone',       "Enter new phone number:"],
            'website'   => ['onboard_website',     "Enter new website (or skip):"],
            'logo'      => ['onboard_logo',        "Upload your new logo:"],
        ];

        foreach ($map as $key => [$newState, $prompt]) {
            if (str_contains($lower, $key)) {
                $state = $newState;
                if ($newState === 'settings_template') {
                    return [$this->buttons($prompt, ['Classic', 'Modern', 'Freelancer', 'Corporate', 'Banded'])];
                }
                if ($newState === 'settings_color') {
                    return [$this->buttons($prompt, $this->colorChoices())];
                }
                if ($newState === 'onboard_logo') {
                    return [$this->upload($prompt)];
                }
                return [$this->msg($prompt)];
            }
        }
        return $this->showSettingsMenu();
    }

    private function stateSettingsColor(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $hex = $this->parseColor($input);
        if ($hex === null) {
            return [$this->buttons("Please pick a colour or type a hex like *#2563eb*:", $this->colorChoices())];
        }
        $this->pm->updateSettings($this->userId, ['accent_color' => $hex]);
        $state = 'idle';
        return [$this->msg("Accent colour updated to *{$hex}*.")];
    }

    private function stateSettingsCurrency(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $lower = strtolower(trim($input));
        if ($lower === 'keep') {
            $state = 'idle';
            return [$this->msg("Currency unchanged.")];
        }
        $cur = strtoupper(trim($input));
        if (!preg_match('/^[A-Z]{3}$/', $cur)) {
            return [$this->buttons("Please enter a valid 3-letter currency code.", ['ZAR', 'USD', 'EUR', 'GBP'])];
        }
        $this->pm->updateSettings($this->userId, ['currency' => $cur]);
        $state = 'idle';
        return [$this->msg("Default currency updated to *{$cur}*.")];
    }

    private function stateSettingsTax(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (!is_numeric($input) || (float)$input < 0 || (float)$input > 100) {
            return [$this->msg("Please enter a number between 0 and 100.")];
        }
        $this->pm->updateSettings($this->userId, ['tax_rate' => (float)$input]);
        $state = 'idle';
        return [$this->msg("Default tax rate updated to *{$input}%*.")];
    }

    private function stateSettingsDiscount(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        if (!is_numeric($input) || (float)$input < 0 || (float)$input > 100) {
            return [$this->msg("Please enter a number between 0 and 100.")];
        }
        $this->pm->updateSettings($this->userId, ['discount' => (float)$input]);
        $state = 'idle';
        return [$this->msg("Default discount updated to *{$input}%*.")];
    }

    private function stateSettingsTemplate(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $tpl = $this->parseTemplate($input);
        $this->pm->updateSettings($this->userId, ['template' => $tpl]);
        $state = 'idle';
        return [$this->msg("Default template updated to *{$tpl}*.")];
    }

    private function stateSettingsTerms(string $input, string &$state, array &$data, ?int &$invoiceId): array
    {
        $this->pm->updateSettings($this->userId, ['payment_terms' => $input]);
        $state = 'idle';
        return [$this->msg("Default payment terms updated to: *{$input}*")];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function showSettingsMenu(): array
    {
        $s = $this->pm->getSettings($this->userId);
        $u = $this->pm->get($this->userId) ?? [];
        $text = "*Current Settings:*\n"
            . "Company: " . ($u['company_name'] ?? '—') . "\n"
            . "Currency: {$s['currency']}\n"
            . "Tax rate: {$s['tax_rate']}%\n"
            . "Discount: {$s['discount']}%\n"
            . "Template: {$s['template']}\n"
            . "Accent colour: " . ($s['accent_color'] ?? '#2563eb') . "\n"
            . "Payment terms: {$s['payment_terms']}\n\n"
            . "What would you like to update?";
        return [
            $this->buttons($text, [
                'Currency', 'Tax', 'Discount', 'Template', 'Color', 'Terms',
                'Company', 'Address', 'Phone', 'Website', 'Bank', 'Logo',
            ]),
        ];
    }

    private function showHelp(string &$state): array
    {
        $state = 'idle';
        return [
            $this->msg(
                "*InvoiceChat Commands:*\n\n"
                . "/new — Create a new invoice\n"
                . "/quote — Create a new quote\n"
                . "/ai — ✨ Describe an invoice in one sentence and I'll draft it\n"
                . "/convert — Convert active quote to invoice\n"
                . "/settings — Update profile & defaults\n"
                . "/history — Browse past invoices & quotes (sidebar)\n"
                . "/paid — Mark current invoice as paid\n"
                . "/accept — Mark current quote as accepted\n"
                . "/cancel — Cancel current operation\n"
                . "/help — Show this help\n\n"
                . "*Plan & credits:* " . APP_URL . "/billing.php\n"
                . "*Logo maker:* " . APP_URL . "/logo.php\n\n"
                . "*During creation:*\n"
                . "• Type values or press quick-reply buttons\n"
                . "• Type *skip* to skip optional fields\n"
                . "• Type *keep* to use default values"
            ),
        ];
    }

    private function buildItemsList(array $items, string $currency): string
    {
        if (empty($items)) return '_No items yet._';
        $lines = ["*Current items:*"];
        foreach ($items as $i => $item) {
            $qty   = (float)$item['quantity'];
            $price = (float)$item['unit_price'];
            $sub   = $qty * $price;
            $lines[] = sprintf(
                "%d. %s × %s @ %s = %s",
                $i + 1,
                $item['product_name'],
                rtrim(rtrim(number_format($qty, 2), '0'), '.'),
                $this->money($price, $currency),
                $this->money($sub, $currency)
            );
        }
        return implode("\n", $lines);
    }

    private function money(float $amount, string $currency): string
    {
        $symbols = [
            'USD'=>'$','EUR'=>'€','GBP'=>'£','JPY'=>'¥',
            'CAD'=>'CA$','AUD'=>'A$','CHF'=>'CHF','CNY'=>'¥',
            'INR'=>'₹','BRL'=>'R$','ZAR'=>'R','NGN'=>'₦',
        ];
        return ($symbols[$currency] ?? $currency . ' ') . number_format($amount, 2);
    }

    private function msg(string $text): array
    {
        return ['type' => 'text', 'content' => $text];
    }

    private function buttons(string $text, array $btns): array
    {
        return ['type' => 'buttons', 'content' => $text, 'buttons' => $btns];
    }

    private function upload(string $text): array
    {
        return ['type' => 'upload', 'content' => $text];
    }

    private function toCamel(string $snake): string
    {
        return str_replace('_', '', ucwords($snake, '_'));
    }

    /** Preset accent colour names offered as quick-reply buttons. */
    private function colorChoices(): array
    {
        return ['Blue', 'Green', 'Teal', 'Purple', 'Red', 'Orange', 'Navy', 'Black'];
    }

    /** Map a colour name or hex string to a normalised hex (or null). */
    private function parseColor(string $input): ?string
    {
        $map = [
            'blue'   => '#2563eb', 'green'  => '#16a34a', 'teal'   => '#0d9488',
            'purple' => '#7c3aed', 'red'    => '#dc2626', 'orange' => '#ea580c',
            'navy'   => '#1e3a8a', 'black'  => '#1a1a1a',
        ];
        $l = strtolower(trim($input));
        foreach ($map as $name => $hex) {
            if (str_contains($l, $name)) return $hex;
        }
        $h = ltrim($l, '#');
        if (preg_match('/^[0-9a-f]{3}$/', $h) || preg_match('/^[0-9a-f]{6}$/', $h)) {
            return '#' . $h;
        }
        return null;
    }

    /** Resolve whether the active document is a quote or invoice. */
    private function docType(array $data, ?int $invoiceId): string
    {
        if (!empty($data['doc_type'])) {
            return $data['doc_type'] === 'quote' ? 'quote' : 'invoice';
        }
        if ($invoiceId) {
            $doc = $this->im->get($invoiceId, $this->userId);
            if ($doc && ($doc['doc_type'] ?? 'invoice') === 'quote') return 'quote';
        }
        return 'invoice';
    }
}
