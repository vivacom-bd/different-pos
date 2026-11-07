<?php

declare(strict_types=1);

namespace App\Support\Receipts;

use Mpdf\Mpdf;
use RuntimeException;

/**
 * Turns a ReceiptBuilder view model into a print page. html() is the source of truth (the browser
 * prints it, @page sets the paper); pdf() needs mPDF, which waits for ext-gd.
 */
final class ReceiptRenderer
{
    public const array PAPERS = ['80', '58', 'a4'];

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $extra
     */
    public function html(string $view, array $receipt, string $paper, array $extra = []): string
    {
        $name = match ($view) {
            'a4' => 'receipts.a4',
            'chalan' => 'receipts.chalan',
            'return' => 'receipts.return',
            'payment' => 'receipts.payment',
            default => 'receipts.thermal',
        };

        return view($name, ['r' => $receipt, 'paper' => self::paper($paper), ...$extra])->render();
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function pdf(string $view, array $receipt, string $paper): string
    {
        if (! class_exists(Mpdf::class)) {
            throw new RuntimeException('PDF receipts need mPDF (mpdf/mpdf), which needs ext-gd. It is not installed yet; print the HTML view instead.');
        }

        $paper = self::paper($paper);
        $format = $paper === 'a4' ? 'A4' : [$paper === '58' ? 58.0 : 80.0, 297.0];
        $mpdf = new Mpdf(['format' => $format, 'margin_left' => 4, 'margin_right' => 4, 'margin_top' => 4, 'margin_bottom' => 4]);
        $mpdf->WriteHTML($this->html($view, $receipt, $paper, ['pdf' => true]));

        return $mpdf->Output('', 'S');
    }

    public static function paper(?string $paper): string
    {
        return in_array($paper, self::PAPERS, true) ? $paper : 'a4';
    }
}
