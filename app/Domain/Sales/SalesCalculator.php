<?php
declare(strict_types=1);

namespace Ecrm\Domain\Sales;

use Ecrm\Support\Money;
use InvalidArgumentException;

final class SalesCalculator
{
    public function calculate(array $items, string $taxMode = 'intra_state', mixed $roundOff = 0): array
    {
        if ($items === []) throw new InvalidArgumentException('At least one line item is required');
        if (!in_array($taxMode, ['intra_state','inter_state'], true)) {
            throw new InvalidArgumentException('Invalid tax mode');
        }

        $lines = [];
        $totals = [
            'gross_paise' => 0,
            'discount_paise' => 0,
            'taxable_paise' => 0,
            'cgst_paise' => 0,
            'sgst_paise' => 0,
            'igst_paise' => 0,
            'tax_paise' => 0,
        ];

        foreach ($items as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0) throw new InvalidArgumentException('Line quantity must be greater than zero');

            $description = trim((string) ($item['description'] ?? $item['name'] ?? ''));
            if ($description === '') throw new InvalidArgumentException('Line description is required');

            $ratePaise = array_key_exists('rate_paise', $item)
                ? (int) $item['rate_paise']
                : Money::toPaise($item['rate'] ?? 0);
            if ($ratePaise < 0) throw new InvalidArgumentException('Line rate cannot be negative');

            $discountBps = array_key_exists('discount_bps', $item)
                ? (int) $item['discount_bps']
                : Money::toBps($item['discount_percent'] ?? 0);
            $taxBps = array_key_exists('tax_bps', $item)
                ? (int) $item['tax_bps']
                : Money::toBps($item['tax_percent'] ?? $item['gst_percent'] ?? 0);

            if ($discountBps < 0 || $discountBps > 10000 || $taxBps < 0 || $taxBps > 10000) {
                throw new InvalidArgumentException('Invalid line percentage');
            }

            $gross = (int) round($quantity * $ratePaise, 0, PHP_ROUND_HALF_UP);
            $discount = Money::applyBps($gross, $discountBps);
            $taxable = $gross - $discount;
            $tax = Money::applyBps($taxable, $taxBps);

            $cgst = 0;
            $sgst = 0;
            $igst = 0;
            if ($taxMode === 'intra_state') {
                $cgst = intdiv($tax, 2);
                $sgst = $tax - $cgst;
            } else {
                $igst = $tax;
            }

            $line = [
                'line_no' => $index + 1,
                'product_id' => $item['product_id'] ?? null,
                'description' => $description,
                'hsn_sac' => trim((string) ($item['hsn_sac'] ?? '')),
                'unit' => trim((string) ($item['unit'] ?? 'Nos')),
                'quantity' => $quantity,
                'rate_paise' => $ratePaise,
                'discount_bps' => $discountBps,
                'discount_paise' => $discount,
                'taxable_paise' => $taxable,
                'tax_bps' => $taxBps,
                'cgst_paise' => $cgst,
                'sgst_paise' => $sgst,
                'igst_paise' => $igst,
                'tax_paise' => $tax,
                'line_total_paise' => $taxable + $tax,
            ];
            $lines[] = $line;

            $totals['gross_paise'] += $gross;
            $totals['discount_paise'] += $discount;
            $totals['taxable_paise'] += $taxable;
            $totals['cgst_paise'] += $cgst;
            $totals['sgst_paise'] += $sgst;
            $totals['igst_paise'] += $igst;
            $totals['tax_paise'] += $tax;
        }

        $roundOffPaise = array_key_exists('round_off_paise', ['round_off_paise' => null]) && false
            ? 0
            : Money::toPaise($roundOff);
        $subtotal = $totals['taxable_paise'] + $totals['tax_paise'];
        $totals['round_off_paise'] = $roundOffPaise;
        $totals['grand_total_paise'] = $subtotal + $roundOffPaise;

        if ($totals['grand_total_paise'] < 0) {
            throw new InvalidArgumentException('Grand total cannot be negative');
        }

        return ['tax_mode' => $taxMode, 'items' => $lines, 'totals' => $totals];
    }
}
