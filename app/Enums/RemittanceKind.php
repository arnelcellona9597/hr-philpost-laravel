<?php

namespace App\Enums;

enum RemittanceKind: string
{
    case Sss = 'sss';
    case Hdmf = 'hdmf';
    case Cmpeai = 'cmpeai';

    public function label(): string
    {
        return match ($this) {
            self::Sss => 'SSS',
            self::Hdmf => 'HDMF (Pag-IBIG)',
            self::Cmpeai => 'CMPEAI',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Sss => 'SSS',
            self::Hdmf => 'Pag-IBIG',
            self::Cmpeai => 'CMPEAI',
        };
    }

    /**
     * The employee column that holds this fund's member number, if there is one.
     */
    public function memberField(): ?string
    {
        return match ($this) {
            self::Sss => 'sss',
            self::Hdmf => 'pagibig',
            self::Cmpeai => null,
        };
    }

    public function memberLabel(): string
    {
        return match ($this) {
            self::Sss => 'SSS number',
            self::Hdmf => 'Pag-IBIG ID / RTN',
            self::Cmpeai => 'Member number',
        };
    }

    public function amountLabel(): string
    {
        return match ($this) {
            self::Sss => 'Contribution',
            self::Hdmf => 'Employee share',
            self::Cmpeai => 'Total payment',
        };
    }

    /**
     * Wording used on the first install. Everything here is editable in the template settings.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Sss => [
                'title' => 'SSS Contributions of Contract of Service Workers',
                'default_amount_centavos' => 75_000,
                'prepared_by_title' => 'Chief, Human Resource Management Section',
                'noted_by_title' => 'Chief, Administrative and Finance Division',
                'voucher_particulars' => 'To payment of COS SSS remittance for the period covered {period}, in the amount of {amount}.',
            ],
            self::Hdmf => [
                'title' => 'Pag-IBIG Fund Membership Contributions',
                'form_code' => 'HQP-PFF-053',
                'membership_program' => 'Pag-IBIG I',
                'default_amount_centavos' => 40_000,
                'default_employer_share_centavos' => 0,
                'default_compensation_centavos' => 500_000,
                'certification_text' => 'I certify that the information in this list is true and correct, and that the amounts were withheld from the members named above.',
                'prepared_by_title' => 'Chief, Human Resource Management Section',
                'noted_by_title' => 'Chief, Administrative and Finance Division',
                'voucher_particulars' => 'To payment of COS Pag-IBIG remittance for the period covered {period}, in the amount of {amount}.',
            ],
            self::Cmpeai => [
                'title' => 'CMPEAI Contributions and Loan Payments',
                'default_amount_centavos' => 30_000,
                'service_charge_basis_points' => 400,
                'prepared_by_title' => 'Chief, Human Resource Management Section',
                'noted_by_title' => 'Chief, Administrative and Finance Division',
                'voucher_particulars' => 'To payment of COS CMPEAI remittance for the period covered {period}, in the amount of {amount}.',
            ],
        };
    }
}
