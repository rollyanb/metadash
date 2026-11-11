<?php
// includes/receipt_parser.php — parser dos recibos de transação da Meta (PDF do zip
// exportado em Business Manager › Faturamento › Transações).
// Cada recibo traz o desmembramento por campanha, o que permite atribuir a cobrança
// aos eventos via event_campaigns / event_tags.
require_once __DIR__ . '/../lib/pdfparser/autoload.php';

class ReceiptParser {

    private static $meses = ['jan'=>1,'fev'=>2,'mar'=>3,'abr'=>4,'mai'=>5,'jun'=>6,
                             'jul'=>7,'ago'=>8,'set'=>9,'out'=>10,'nov'=>11,'dez'=>12];

    private static function money($s) {
        return (float)str_replace(',', '.', str_replace('.', '', $s));
    }

    private static function parseDate($s) {
        if (!preg_match('/(\d+) de (\p{L}+)\.? de (\d{4})(?:\s+(\d{2}):(\d{2}))?/u', $s, $m)) return null;
        $mes = self::$meses[mb_strtolower(mb_substr($m[2], 0, 3))] ?? 0;
        if (!$mes) return null;
        $d = sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]);
        return isset($m[4]) ? $d . ' ' . $m[4] . ':' . $m[5] . ':00' : $d;
    }

    /** Extrai uma transação de um PDF de recibo. Retorna array ou null se não for um recibo. */
    public static function parseFile($path, $filename = '') {
        $parser = new \Smalot\PdfParser\Parser();
        try { $text = $parser->parseFile($path)->getText(); }
        catch (Exception $e) { return null; }
        if (strpos($text, 'Recibo para') === false) return null;

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
        $get = function($pattern) use ($text) {
            return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
        };

        $tid = null;
        if ($filename && preg_match('/#([\d-]+)\.pdf/i', $filename, $m)) $tid = $m[1];
        if (!$tid) $tid = $get('/Identificação da transação\s*\n\s*([\d-]+)/u');
        if (!$tid && preg_match('/(\d{12,}-\d{12,})/', $text, $m)) $tid = $m[1];

        $status = 'desconhecido';
        foreach ($lines as $l) {
            if (preg_match('/^(Pago|Falha|Pendente|Reembolsado|Estornado)$/u', $l)) { $status = $l; break; }
        }

        $tx = [
            'transaction_id' => $tid,
            'account_name'   => $get('/Recibo para (.+)/u'),
            'billing_account'=> $get('/Número de identificação da conta:\s*(\d+)/u'),
            'paid_at'        => self::parseDate((string)$get('/Data de pagamento\s*\n\s*(.+)/u')),
            'status'         => $status,
            'reference'      => $get('/Número de referência:\s*(\S+)/u'),
            'total'          => null,
            'subtotal'       => null,
            'taxes'          => 0.0,
            'payment_method' => $get('/Forma de pagamento\s*\n\s*(.+)/u'),
            'campaigns'      => [],
        ];
        if (preg_match('/^R\$([\d\.,]+)$/mu', $text, $m))            $tx['total']    = self::money($m[1]);
        if (preg_match('/Subtotal:\s*R\$([\d\.,]+)/u', $text, $m))   $tx['subtotal'] = self::money($m[1]);
        foreach (['COFINS','PIS','ISS','VAT','IVA'] as $imp) {
            if (preg_match('/'.$imp.':\s*R?\$?\s*([\d\.,]+)/u', $text, $m)) $tx['taxes'] += self::money($m[1]);
        }

        // Campanhas: nome (linha anterior) / "De ... a ..." / R$valor (linha seguinte)
        $skip = ['Recibo para','Número de identificação','Data de pagamento','Forma de pagamento',
                 'Número de referência','Identificação da transação','Tipo de produto','Meta anúncios',
                 'Campanhas','Subtotal','COFINS','PIS','ISS','VAT'];
        for ($i = 0; $i < count($lines); $i++) {
            if (!preg_match('/^De (\d+ de \p{L}+\.? de \d{4}.*?) a (\d+ de \p{L}+\.? de \d{4}.*)$/u', $lines[$i], $pm)) continue;

            $name = null;
            for ($j = $i - 1; $j >= max(0, $i - 4); $j--) {
                $c = $lines[$j];
                if ($c === '' || strpos($c, "\t") !== false || mb_stripos($c, 'Impress') !== false) continue;
                if (preg_match('/^R\$[\d\.,]+$/u', $c)) continue;
                $isHeader = false;
                foreach ($skip as $h) if (mb_stripos($c, $h) === 0) { $isHeader = true; break; }
                if ($isHeader) continue;
                $name = $c; break;
            }
            $value = null;
            for ($j = $i + 1; $j < min(count($lines), $i + 4); $j++) {
                if (preg_match('/^R\$([\d\.,]+)$/u', $lines[$j], $vm)) { $value = self::money($vm[1]); break; }
                if (strpos($lines[$j], "\t") !== false) break; // já entrou nos conjuntos
            }
            if ($name !== null && $value !== null) {
                $tx['campaigns'][] = [
                    'name'         => $name,
                    'value'        => $value,
                    'period_start' => self::parseDate($pm[1]),
                    'period_end'   => self::parseDate($pm[2]),
                ];
            }
        }
        return $tx;
    }

    /** Extrai todas as transações de um zip de recibos. Retorna [transações, erros]. */
    public static function parseZip($zipPath) {
        $out = []; $errs = [];
        if (!class_exists('ZipArchive')) {
            return [[], ['Extensão PHP "zip" não está habilitada no servidor. Ative-a no hPanel (Configurações PHP › Extensões) e tente de novo.']];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return [[], ['Não foi possível abrir o zip']];
        $tmp = sys_get_temp_dir() . '/rcpt_' . bin2hex(random_bytes(6));
        mkdir($tmp);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = $zip->getNameIndex($i);
            if (!preg_match('/\.pdf$/i', $nm) || strpos($nm, '__MACOSX') !== false) continue;
            $dest = $tmp . '/f' . $i . '.pdf';
            copy('zip://' . $zipPath . '#' . $nm, $dest);
            $tx = self::parseFile($dest, basename($nm));
            @unlink($dest);
            if ($tx && $tx['transaction_id']) $out[] = $tx;
            else $errs[] = basename($nm);
        }
        $zip->close(); @rmdir($tmp);
        return [$out, $errs];
    }
}
