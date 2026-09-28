<?php

/*
| Default port names of common MikroTik models. Used to draw the port list
| on the Add router page. "Read ports from router" replaces this with the
| real list (including wireless interfaces), and every port is checked on
| the router before anything is changed.
*/

$ether = fn (int $to, int $from = 1) => array_map(fn ($i) => "ether{$i}", range($from, $to));
$named = fn (string $prefix, int $to) => array_map(fn ($i) => "{$prefix}{$i}", range(1, $to));

return [
    'hap-lite' => ['label' => 'hAP lite (RB941-2nD)', 'group' => 'hAP / hEX', 'ports' => $ether(4)],
    'hap-ac2'  => ['label' => 'hAP ac² (RBD52G)', 'group' => 'hAP / hEX', 'ports' => $ether(5)],
    'hap-ax2'  => ['label' => 'hAP ax²', 'group' => 'hAP / hEX', 'ports' => $ether(5)],
    'hap-ax3'  => ['label' => 'hAP ax³', 'group' => 'hAP / hEX', 'ports' => $ether(5)],
    'hex'      => ['label' => 'hEX (RB750Gr3)', 'group' => 'hAP / hEX', 'ports' => $ether(5)],
    'hex-s'    => ['label' => 'hEX S (RB760iGS)', 'group' => 'hAP / hEX', 'ports' => [...$ether(5), 'sfp1']],

    'rb4011'   => ['label' => 'RB4011iGS+RM', 'group' => 'RouterBOARD', 'ports' => [...$ether(10), 'sfp-sfpplus1']],
    'rb5009'   => ['label' => 'RB5009UG+S+IN', 'group' => 'RouterBOARD', 'ports' => [...$ether(8), 'sfp-sfpplus1']],
    'rb1100'   => ['label' => 'RB1100AHx4', 'group' => 'RouterBOARD', 'ports' => $ether(13)],

    'ccr1009'     => ['label' => 'CCR1009-7G-1C-1S+', 'group' => 'Cloud Core Router', 'ports' => [...$ether(7), 'combo1', 'sfp-sfpplus1']],
    'ccr1036-12g' => ['label' => 'CCR1036-12G-4S', 'group' => 'Cloud Core Router', 'ports' => [...$ether(12), ...$named('sfp', 4)]],
    'ccr2004-16g' => ['label' => 'CCR2004-16G-2S+', 'group' => 'Cloud Core Router', 'ports' => [...$ether(16), ...$named('sfp-sfpplus', 2)]],
    'ccr2004-1g'  => ['label' => 'CCR2004-1G-12S+2XS', 'group' => 'Cloud Core Router', 'ports' => ['ether1', ...$named('sfp-sfpplus', 12), 'sfp28-1', 'sfp28-2']],
    'ccr2116'     => ['label' => 'CCR2116-12G-4S+', 'group' => 'Cloud Core Router', 'ports' => [...$ether(13), ...$named('sfp-sfpplus', 4)]],
];
