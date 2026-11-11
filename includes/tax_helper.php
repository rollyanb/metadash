<?php
// includes/tax_helper.php
// Cálculo centralizado de impostos sobre gasto em Meta Ads.
// Alíquotas em um único lugar — ajuste aqui se o regime tributário mudar.
//
// IMPORTANTE: a Meta calcula esses impostos "por dentro" (gross-up), ou seja,
// cada imposto é uma % do valor TOTAL cobrado, não do subtotal (gasto em tráfego).
// Fórmula: total = subtotal / (1 - taxa_total)
// Confirmado batendo com nota fiscal real da Meta (recibo de transação):
//   Subtotal R$1.507,07 -> COFINS R$130,38 (7,60%) + PIS R$28,31 (1,65%) + ISS R$49,75 (2,90%)
//   Total R$1.715,51 -> imposto R$208,44 (não R$183,11, que seria o cálculo "por fora")

const META_TAX_ISS_RATE        = 0.029;   // ISS 2,90%
const META_TAX_PIS_RATE        = 0.0165;  // PIS 1,65%
const META_TAX_COFINS_RATE     = 0.076;   // COFINS 7,60%
const META_TAX_TOTAL_RATE      = META_TAX_ISS_RATE + META_TAX_PIS_RATE + META_TAX_COFINS_RATE; // 12,15%

/**
 * Calcula os impostos sobre um valor de gasto em Meta Ads (cálculo "por dentro",
 * igual ao usado pela Meta na fatura real).
 * @param float $metaSpend Valor gasto em tráfego (Meta Ads) — subtotal líquido
 * @return array{iss: float, pis: float, cofins: float, pis_cofins: float, total: float, total_geral: float}
 */
function calcMetaTax(float $metaSpend): array {
    if ($metaSpend <= 0) {
        return ['iss'=>0,'pis'=>0,'cofins'=>0,'pis_cofins'=>0,'total'=>0,'total_geral'=>0];
    }

    $totalGeral = $metaSpend / (1 - META_TAX_TOTAL_RATE);

    $iss    = $totalGeral * META_TAX_ISS_RATE;
    $pis    = $totalGeral * META_TAX_PIS_RATE;
    $cofins = $totalGeral * META_TAX_COFINS_RATE;

    return [
        'iss'         => $iss,
        'pis'         => $pis,
        'cofins'      => $cofins,
        'pis_cofins'  => $pis + $cofins,
        'total'       => $iss + $pis + $cofins,
        'total_geral' => $totalGeral,
    ];
}