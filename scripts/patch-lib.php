<?php
$p = $argv[1] ?? "";
$t = file_get_contents($p);
if ($t === false) { fwrite(STDERR, "read fail\n"); exit(1); }
$t = str_replace("\r\n", "\n", $t);
$t = str_replace("mysqli \$db", "\$db", $t);
$t = str_replace("mysqli_real_escape_string(\$db,", "robe_esc(\$db,", $t);
$t = str_replace("mysqli_fetch_assoc(", "robe_fetch(", $t);
$t = str_replace("mysqli_num_rows(", "robe_num(", $t);
$t = str_replace("ROCAD payment", "Service", $t);
$t = str_replace(
"/**
 * NRS e-Invoice integration helpers for ROCAD Finance.
 * Maps paid ROCAD invoice payments to an e-invoice provider.
 *
 * Current default provider: ByteInvoice (APP gateway).
 * Future provider: nrs_direct (FIRSMBS / NRS API).
 */",
"/**
 * NRS e-Invoice protocol for Robes eInvoice.
 * Ported from the Rocad Finance module so a company can clear invoices
 * with NRS without embedding them in another system.
 */",
$t
);
$n1 = 0;
$t = str_replace("'isic_code' => \$isic,", "'isic_code' => robes_line_isic(\$li, \$isic),", $t, $n1);
$n2 = 0;
$t = str_replace("'service_category' => \$serviceCategory,", "'service_category' => robes_line_category(\$li, \$serviceCategory),", $t, $n2);
$n3 = 0;
$t = preg_replace(
    "/\\\$isic = trim\\(\\(string\\) \\(\\\$settings\\['default_isic'\\] \\?\\? '4210'\\)\\);\\n        if \\(\\\$isic === ''\\) \\{\\n            \\\$isic = '4210';\\n        \\}/",
    "\$isic = trim((string) (\$settings['default_isic'] ?? ''));",
    $t,
    1,
    $n3
);
$n4 = 0;
$t = preg_replace(
    "/\\\$serviceCategory = trim\\(\\(string\\) \\(\\\$settings\\['default_service_category'\\] \\?\\? 'Construction of roads and railways'\\)\\);\\n        if \\(\\\$serviceCategory === ''\\) \\{\\n            \\\$serviceCategory = 'Construction of roads and railways';\\n        \\}/",
    "\$serviceCategory = trim((string) (\$settings['default_service_category'] ?? ''));",
    $t,
    1,
    $n4
);
file_put_contents($p, $t);
echo "isic_key=$n1 category_key=$n2 isic_default=$n3 category_default=$n4 mysqli=" . substr_count($t, "mysqli") . "\n";
if ($n1 < 1 || $n2 < 1 || $n3 < 1 || $n4 < 1) exit(1);
