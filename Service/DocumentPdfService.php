<?php

namespace Plugin\InvoiceReceiptMail\Service;

use Eccube\Entity\BaseInfo;
use Eccube\Entity\Order;
use Eccube\Entity\OrderItem;
use Eccube\Repository\BaseInfoRepository;
use Plugin\InvoiceReceiptMail\Entity\Config;

/**
 * 請求書（適格請求書）・領収書の PDF を作る。
 *
 * 金額はすべて EC-CUBE 本体の計算結果（注文メールと同じ値）を使う。
 */
class DocumentPdfService
{
    /** EC-CUBE 本体の納品書と同じ日本語フォント */
    private const FONT = 'kozgopromedium';

    /** 英数字用の欧文フォント（日本語フォントの英数字は字間が詰まって見えるため） */
    private const LATIN_FONT = 'helvetica';

    /** 標準税率。これより低い税率の商品に ※（軽減税率対象）を付ける */
    private const STANDARD_TAX_RATE = 10.0;

    /** 社印の大きさ（mm） */
    private const STAMP_SIZE = 22;

    /** @var BaseInfo */
    private $BaseInfo;

    /** @var StampStorage */
    private $stampStorage;

    public function __construct(BaseInfoRepository $baseInfoRepository, StampStorage $stampStorage)
    {
        $this->BaseInfo = $baseInfoRepository->get();
        $this->stampStorage = $stampStorage;
    }

    public static function invoiceNo(Order $Order): string
    {
        return 'INV-'.$Order->getOrderNo();
    }

    public static function receiptNo(Order $Order): string
    {
        return 'RCP-'.$Order->getOrderNo();
    }

    public function createInvoice(Order $Order, Config $Config): string
    {
        $pdf = $this->newPdf('請求書 '.self::invoiceNo($Order));

        $issueDate = $Order->getOrderDate() ?: new \DateTime();
        $paidDate = $this->paidDate($Order, $Config);
        $head = $this->title('請　求　書', false)
            .$this->partiesBlock($Order, [
                '請求書番号' => self::invoiceNo($Order),
                '発行日' => $this->date($issueDate),
                'ご注文番号' => $Order->getOrderNo(),
                'ご注文日' => $this->date($Order->getOrderDate()),
            ]);
        $body = $this->amountBox('ご請求金額（税込）', $Order->getPaymentTotal(), $paidDate !== null)
            .$this->itemsTable($Order)
            .$this->sideBySide(
                $this->infoInner([
                    'お支払方法' => $Order->getPaymentMethod(),
                    'お支払日' => $paidDate ? $this->date($paidDate).'（お支払済み）' : null,
                    // 支払済みなら振込先などの案内は不要
                    '備考' => $paidDate ? null : $Config->getInvoiceNote(),
                ], 30),
                $this->taxInner($Order)
            );

        $this->write($pdf, $head, $body);

        return $pdf->Output('', 'S');
    }

    /**
     * 支払済みならその日付を返す。
     * - 注文時に支払いが完了する支払方法（カード決済など）：決済日、なければ注文日
     * - それ以外：入金済みになって入金日が記録されていれば入金日
     */
    private function paidDate(Order $Order, Config $Config): ?\DateTimeInterface
    {
        $Payment = $Order->getPayment();
        if ($Payment && in_array((int) $Payment->getId(), $Config->getPrepaidPaymentIds(), true)) {
            return $Order->getPaymentDate() ?: ($Order->getOrderDate() ?: new \DateTime());
        }

        return $Order->getPaymentDate();
    }

    /**
     * @param bool $reissue 2 回目以降の発行なら「再発行」と表示する
     * @param string|null $addressee 宛名（敬称込み）。null なら注文者
     * @param string|null $proviso 但し書き。null なら設定の値
     */
    public function createReceipt(Order $Order, Config $Config, bool $reissue, ?string $addressee = null, ?string $proviso = null): string
    {
        $pdf = $this->newPdf('領収書 '.self::receiptNo($Order));

        // 請求書と同じ並び（宛名・書類情報 → 金額 → 明細 → 支払情報・税率別内訳）
        $paidDate = $this->paidDate($Order, $Config) ?: new \DateTime();
        $head = $this->title('領　収　書', $reissue)
            .$this->partiesBlock($Order, [
                '領収書番号' => self::receiptNo($Order),
                '発行日' => $this->date(new \DateTime()),
                'ご注文番号' => $Order->getOrderNo(),
                'ご注文日' => $this->date($Order->getOrderDate()),
            ], $addressee);
        $body = $this->amountBox('領収金額（税込）', $Order->getPaymentTotal(), false,
                '但し　'.$this->e($proviso ?? $Config->getReceiptProviso()).'　上記正に領収いたしました。')
            .$this->itemsTable($Order)
            .$this->sideBySide(
                $this->infoInner([
                    '受領日' => $this->date($paidDate),
                    'お支払方法' => $Order->getPaymentMethod(),
                ], 30),
                $this->taxInner($Order)
            );

        $this->write($pdf, $head, $body);

        return $pdf->Output('', 'S');
    }

    /**
     * 宛名・発行者の欄を書いたあと、発行者欄の右下に社印を重ねてから本文を書く
     */
    private function write(\TCPDF $pdf, string $head, string $body): void
    {
        $pdf->writeHTML($this->latin($head), false, false, true, false, '');
        $this->stamp($pdf, $pdf->GetY());
        $pdf->writeHTML($this->latin('<br>'.$body), true, false, true, false, '');
    }

    /**
     * @param float $bottom 発行者欄の下端（mm）
     */
    private function stamp(\TCPDF $pdf, float $bottom): void
    {
        $path = $this->stampStorage->path();
        if ($path === null) {
            return;
        }
        $margins = $pdf->getMargins();
        $x = $pdf->getPageWidth() - $margins['right'] - self::STAMP_SIZE;
        $y = $bottom - self::STAMP_SIZE + 2;
        // 乗算で重ねて、下の文字が読めるようにする（朱肉で押したときと同じ見え方）
        $pdf->SetAlpha(1, 'Multiply');
        $pdf->Image($path, $x, $y, self::STAMP_SIZE, self::STAMP_SIZE, '', '', '', true, 300, '', false, false, 0, true);
        $pdf->SetAlpha(1, 'Normal');
        $pdf->SetY($bottom);
    }

    private function newPdf(string $title): \TCPDF
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator($this->BaseInfo->getShopName());
        $pdf->SetAuthor($this->BaseInfo->getShopName());
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 16, 18);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setCellHeightRatio(1.4);
        $pdf->SetFont(self::FONT, '', 9.5);
        $pdf->AddPage();

        return $pdf;
    }

    private function title(string $title, bool $reissue): string
    {
        $mark = $reissue ? '<span style="font-size:11pt;">（再発行）</span>' : '';

        return '<h1 style="text-align:center;font-size:20pt;letter-spacing:4px;">'.$title.$mark.'</h1>';
    }

    /**
     * 宛名（左）と、書類情報・発行者（右）
     *
     * @param array<string, string|null> $meta
     * @param string|null $addressee 宛名（敬称込み）。指定されたら注文者の名前・住所の代わりに出す
     */
    private function partiesBlock(Order $Order, array $meta, ?string $addressee = null): string
    {
        $to = $addressee !== null ? '<span style="font-size:13pt;">'.$this->e($addressee).'</span>' : $this->orderAddressee($Order);

        $metaRows = '';
        foreach ($meta as $label => $value) {
            $metaRows .= '<tr><td width="40%">'.$this->e($label).'</td><td width="60%" align="right">'.$this->e((string) $value).'</td></tr>';
        }

        return '<table cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td width="55%"><table cellpadding="5" border="0"><tr><td style="border-bottom:0.6pt solid #333;">'.$to.'</td></tr></table></td>'
            .'<td width="5%"></td>'
            .'<td width="40%"><table cellpadding="1.5" border="0" style="font-size:8.5pt;">'.$metaRows.'</table>'
            .'<br><br>'.$this->issuer().'</td>'
            .'</tr></table>';
    }

    private function orderAddressee(Order $Order): string
    {
        $to = '';
        if ($Order->getCompanyName()) {
            $to .= $this->e($Order->getCompanyName()).'<br>';
        }
        $to .= '<span style="font-size:13pt;">'.$this->e($Order->getName01().' '.$Order->getName02()).'　様</span>';
        $address = trim(($Order->getPref() ? $Order->getPref()->getName() : '').$Order->getAddr01().' '.$Order->getAddr02());
        if ($Order->getPostalCode() || $address !== '') {
            $to .= '<br><span style="font-size:8.5pt;">〒'.$this->e($this->postal($Order->getPostalCode())).' '.$this->e($address).'</span>';
        }

        return $to;
    }

    private function issuer(): string
    {
        $b = $this->BaseInfo;
        $name = $b->getCompanyName() ?: $b->getShopName();
        $lines = ['<b style="font-size:10.5pt;">'.$this->e($name).'</b>'];
        if ($b->getCompanyName() && $b->getShopName() && $b->getShopName() !== $b->getCompanyName()) {
            $lines[] = $this->e($b->getShopName());
        }
        $address = trim(($b->getPref() ? $b->getPref()->getName() : '').$b->getAddr01().' '.$b->getAddr02());
        if ($b->getPostalCode() || $address !== '') {
            $lines[] = '〒'.$this->e($this->postal($b->getPostalCode())).' '.$this->e($address);
        }
        if ($b->getPhoneNumber()) {
            $lines[] = 'TEL '.$this->e($b->getPhoneNumber());
        }
        if ($b->getEmail01()) {
            $lines[] = $this->e($b->getEmail01());
        }
        if ($b->getInvoiceRegistrationNumber()) {
            $no = $b->getInvoiceRegistrationNumber();
            $no = preg_match('/^T/i', $no) ? strtoupper($no) : 'T'.$no;
            $lines[] = '登録番号 '.$this->e($no);
        }

        return '<span style="font-size:8.5pt;">'.implode('<br>', $lines).'</span>';
    }

    /**
     * @param string|null $note 金額の下に添える一文（HTML。領収書の但し書きなど）
     */
    private function amountBox(string $label, $amount, bool $paid, ?string $note = null): string
    {
        $stamp = $paid
            ? '　<span style="font-size:11pt;color:#ffffff;background-color:#555555;">　お支払済み　</span>'
            : '';
        $noteRow = $note !== null
            ? '<tr><td width="70%" style="font-size:9pt;">'.$note.'</td><td width="30%"></td></tr>'
            : '';

        return '<table cellpadding="6" border="0"><tr>'
            .'<td width="70%" style="border-bottom:1.2pt solid #333;"><span style="font-size:10pt;">'.$label.'</span>'
            .'　<span style="font-size:17pt;">¥'.$this->yen($amount).'-</span>'.$stamp.'</td>'
            .'<td width="30%"></td></tr>'.$noteRow.'</table><br><br>';
    }

    /**
     * 明細と合計（注文メールと同じ並び・同じ値）
     */
    private function itemsTable(Order $Order): string
    {
        $reduced = $this->reducedRate($Order);
        $head = 'style="background-color:#333333;color:#ffffff;font-size:8.5pt;"';
        $html = '<table cellpadding="5" border="0" style="font-size:8.5pt;">'
            .'<tr '.$head.'><td width="55%">品名</td><td width="17%" align="right">単価（税込）</td><td width="10%" align="right">数量</td><td width="18%" align="right">金額（税込）</td></tr>';

        /** @var OrderItem $Item */
        foreach ($Order->getMergedProductOrderItems() as $Item) {
            $name = $Item->getProductName();
            foreach ([$Item->getClassCategoryName1(), $Item->getClassCategoryName2()] as $class) {
                if ($class) {
                    $name .= ' / '.$class;
                }
            }
            $mark = ($reduced !== null && (float) $Item->getTaxRate() === $reduced) ? ' ※' : '';
            $html .= '<tr><td style="border-bottom:0.3pt solid #ccc;">'.$this->e($name).$mark.'</td>'
                .'<td align="right" style="border-bottom:0.3pt solid #ccc;">¥'.$this->yen($Item->getPriceIncTax()).'</td>'
                .'<td align="right" style="border-bottom:0.3pt solid #ccc;">'.number_format($Item->getQuantity()).'</td>'
                .'<td align="right" style="border-bottom:0.3pt solid #ccc;">¥'.$this->yen($Item->getTotalPrice()).'</td></tr>';
        }

        $rows = [['小計', $Order->getSubtotal()]];
        if ((float) $Order->getCharge() !== 0.0) {
            $rows[] = ['手数料', $Order->getCharge()];
        }
        if ((float) $Order->getDeliveryFeeTotal() !== 0.0) {
            $rows[] = ['送料', $Order->getDeliveryFeeTotal()];
        }
        if ((float) $Order->getTaxableDiscount() !== 0.0) {
            $rows[] = ['値引き', $Order->getTaxableDiscount()];
        }
        $rows[] = ['合計', $Order->getTaxableTotal(), true];
        foreach ($Order->getTaxFreeDiscountItems() as $Item) {
            $rows[] = [$Item->getProductName(), $Item->getTotalPrice()];
        }
        $rows[] = ['お支払い合計', $Order->getPaymentTotal(), true];

        foreach ($rows as $row) {
            $style = !empty($row[2]) ? 'font-size:9.5pt;border-top:0.6pt solid #333;' : '';
            $html .= '<tr><td></td><td colspan="2" align="right" style="'.$style.'">'.$this->e($row[0]).'</td>'
                .'<td align="right" style="'.$style.'">¥'.$this->yen($row[1]).'</td></tr>';
        }
        $html .= '</table>';

        if ($reduced !== null) {
            $html .= '<p style="font-size:8pt;">※ は軽減税率（'.$this->rate($reduced).'%）対象です。</p>';
        }

        return $html.'<br>';
    }

    /**
     * 税率ごとの対象額と消費税額（適格請求書の記載事項）
     */
    private function taxInner(Order $Order): string
    {
        $totals = $Order->getTotalByTaxRate();
        if (!$totals) {
            return '';
        }
        $taxes = $Order->getTaxByTaxRate();
        $html = '<table cellpadding="4" border="0" style="font-size:8.5pt;">'
            .'<tr style="background-color:#eeeeee;"><td width="30%">税率</td><td width="40%" align="right">対象金額（税込）</td><td width="30%" align="right">うち消費税</td></tr>';
        foreach ($totals as $rate => $total) {
            $html .= '<tr><td style="border-bottom:0.3pt solid #ccc;">'.$this->rate($rate).'%対象</td>'
                .'<td align="right" style="border-bottom:0.3pt solid #ccc;">¥'.$this->yen($total).'</td>'
                .'<td align="right" style="border-bottom:0.3pt solid #ccc;">¥'.$this->yen($taxes[$rate] ?? 0).'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * @param array<string, string|null> $rows 値が空の行は出さない
     * @param int $labelWidth 見出し列の幅（%）
     */
    private function infoInner(array $rows, int $labelWidth): string
    {
        $html = '';
        foreach ($rows as $label => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }
            $html .= '<tr><td width="'.$labelWidth.'%" style="background-color:#eeeeee;">'.$this->e($label).'</td>'
                .'<td width="'.(100 - $labelWidth).'%">'.nl2br($this->e($value)).'</td></tr>';
        }

        return $html === '' ? '' : '<table cellpadding="5" border="0" style="font-size:8.5pt;">'.$html.'</table>';
    }

    /**
     * 2 つの表を左右に並べ、上端をそろえる
     */
    private function sideBySide(string $left, string $right): string
    {
        return '<table cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td width="44%">'.$left.'</td><td width="6%"></td><td width="50%">'.$right.'</td>'
            .'</tr></table><br><br>';
    }

    /**
     * 軽減税率（標準税率 10% 未満、0% を除く）の商品があれば、その税率を返す
     */
    private function reducedRate(Order $Order): ?float
    {
        foreach ($Order->getMergedProductOrderItems() as $Item) {
            $rate = (float) $Item->getTaxRate();
            if ($rate > 0 && $rate < self::STANDARD_TAX_RATE) {
                return $rate;
            }
        }

        return null;
    }

    private function yen($value): string
    {
        return number_format((float) $value);
    }

    private function rate($rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }

    private function date(?\DateTimeInterface $date): string
    {
        return $date ? $date->format('Y年n月j日') : '';
    }

    private function postal(?string $code): string
    {
        $code = (string) $code;

        return preg_match('/^\d{7}$/', $code) ? substr($code, 0, 3).'-'.substr($code, 3) : $code;
    }

    /**
     * 表示テキスト中の英数字（金額・日付・番号・英字の商品名など）を欧文フォントにする。
     * タグ・属性の中には手を付けない。
     */
    private function latin(string $html): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }
            $parts[$i] = preg_replace(
                '/[A-Za-z0-9¥$,.\-\/:@+%#&;()]+(?: [A-Za-z0-9¥$,.\-\/:@+%#&;()]+)*/u',
                '<span style="font-family:'.self::LATIN_FONT.';">$0</span>',
                $part
            );
        }

        return implode('', $parts);
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
