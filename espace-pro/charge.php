<?php
// Tableau de charge : chantiers par statut, chiffre d'affaires planifié, occupation des équipes, par semaine ou par mois
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
if (planning_niveau() !== 'tout') { http_response_code(403); page_start('Accès réservé', 'charge.php'); echo '<p class="lead">Le tableau de charge est réservé à l\'administration et au secrétariat.</p>'; page_end(); exit; }
$par = ($_GET['par'] ?? 'semaine') === 'mois' ? 'mois' : 'semaine';
$periodes = [];
if ($par === 'semaine') { $t = strtotime('monday this week'); for ($i = 0; $i < 12; $i++) { $d = date('Y-m-d', strtotime("+$i week", $t)); $periodes[] = [$d, date('Y-m-d', strtotime("$d +7 days")), 'S' . date('W', strtotime($d)) . ' · ' . date('d/m', strtotime($d))]; } }
else { for ($i = 0; $i < 6; $i++) { $d = date('Y-m-01', strtotime("first day of +$i month")); $periodes[] = [$d, date('Y-m-01', strtotime("$d +1 month")), ucfirst(MOIS_FR[(int)date('n', strtotime($d))]) . ' ' . date('Y', strtotime($d))]; } }
$nbPers = count(personnel_liste());
$cat = function (array $e): string {
    if ($e['statut'] === 'termine') return 'termine';
    if ($e['statut'] === 'confirme') return 'valide';
    if ($e['statut'] === 'propose' && $e['proposition_le']) return $e['client_reponse'] === 'autre' ? 'autre' : 'attente';
    return 'planifie';
};
const CATS = ['planifie' => ['Planifié (date non envoyée)', '#3b6ea8'], 'attente' => ['Validation client en cours', '#c77a12'], 'autre' => ['Le client demande une autre date', '#e6332a'], 'valide' => ['Validé par le client', '#8caa31'], 'termine' => ['Terminé', '#683c11']];
$lignes = [];
foreach ($periodes as [$du, $au, $lib]) {
    $L = ['lib' => $lib, 'du' => $du, 'n' => array_fill_keys(array_keys(CATS), 0), 'ca' => array_fill_keys(array_keys(CATS), 0.0), 'jh' => 0.0, 'abs' => 0.0, 'facture' => 0.0, 'paye' => 0.0];
    foreach (evt_periode("$du 00:00:00", "$au 00:00:00") as $e) {
        $jours = 0; for ($t = max(strtotime($du), strtotime(substr($e['debut'], 0, 10))); $t < min(strtotime($au), strtotime($e['fin'])); $t = strtotime('+1 day', $t)) if ((int)date('N', $t) <= 5 && !est_ferie(date('Y-m-d', $t))) $jours++;
        $h = (strtotime($e['fin']) - strtotime($e['debut'])) / 3600; $fraction = (int)$e['journee'] || $h >= 6 ? $jours : min(1, $h / max(1, heures_jour()));
        if ($e['type'] === 'absence') { $L['abs'] += count($e['personnel']) * $fraction; continue; }
        if (!in_array($e['type'], ['chantier', 'urgence'], true)) { $L['jh'] += count($e['personnel']) * min($fraction, $h / max(1, heures_jour())); continue; }
        $L['jh'] += count($e['personnel']) * $fraction;
        if ($e['type'] !== 'chantier' || substr($e['debut'], 0, 10) < $du) continue; // montant compté sur la période de début
        $k = $cat($e); $L['n'][$k]++; $L['ca'][$k] += (float)$e['devis_montant'];
        if ($e['pl_statut'] === 'payee') $L['paye'] += (float)$e['devis_montant']; elseif ($e['pl_facture_id'] !== '') $L['facture'] += (float)$e['devis_montant'];
    }
    $ouv = 0; for ($t = strtotime($du); $t < strtotime($au); $t = strtotime('+1 day', $t)) if ((int)date('N', $t) <= 5 && !est_ferie(date('Y-m-d', $t))) $ouv++;
    $L['capa'] = max(0, $nbPers * $ouv - $L['abs']);
    $lignes[] = $L;
}
$aPl = q("SELECT COUNT(*) n, COALESCE(SUM(devis_montant),0) ca, COALESCE(SUM(devis_signe),0) signes FROM evenements WHERE statut = 'a_planifier' AND type = 'chantier'")->fetch();
$tot = fn(string $k) => array_sum(array_map(fn($L) => array_sum($L[$k]), $lignes));
page_start('Tableau de charge', 'charge.php', ['planning.php', 'Planning']);
?>
<style>.ch th,.ch td{text-align:right;white-space:nowrap}.ch th:first-child,.ch td:first-child{text-align:left}.barre{height:10px;border-radius:5px;background:#e8ebe2;min-width:90px;overflow:hidden}.barre i{display:block;height:100%}
.leg{display:flex;flex-wrap:wrap;gap:12px;font-size:.85rem}.leg span::before{content:"";display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;background:var(--c)}
.k{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:0 0 14px}.k div{background:var(--blanc);border:1px solid #e1e5da;border-radius:12px;padding:10px 12px}.k b{display:block;font-size:1.35rem;color:var(--brun)}</style>
<p><a class="mini <?= $par === 'semaine' ? 'on' : '' ?>" href="?par=semaine">Par semaine (12)</a> <a class="mini" href="?par=mois">Par mois (6)</a></p>
<div class="k">
  <div><span class="small">À planifier</span><b><?= (int)$aPl['n'] ?></b><span class="small"><?= money((float)$aPl['ca']) ?> HT · <?= (int)$aPl['signes'] ?> devis signé(s)</span></div>
  <div><span class="small">Planifié sur la période</span><b><?= money($tot('ca')) ?></b><span class="small">HT, <?= array_sum(array_map(fn($L) => array_sum($L['n']), $lignes)) ?> chantier(s)</span></div>
  <div><span class="small">Validé par les clients</span><b><?= money(array_sum(array_map(fn($L) => $L['ca']['valide'] + $L['ca']['termine'], $lignes))) ?></b><span class="small">HT</span></div>
  <div><span class="small">En attente du client</span><b><?= money(array_sum(array_map(fn($L) => $L['ca']['attente'] + $L['ca']['autre'], $lignes))) ?></b><span class="small">HT</span></div>
  <?php if (pennylane_pret()): ?><div><span class="small">Facturé / encaissé (Pennylane)</span><b><?= money(array_sum(array_column($lignes, 'facture')) + array_sum(array_column($lignes, 'paye'))) ?></b><span class="small">dont <?= money(array_sum(array_column($lignes, 'paye'))) ?> payé</span></div><?php endif; ?>
</div>
<div class="leg"><?php foreach (CATS as [$l, $c]): ?><span style="--c:<?= $c ?>"><?= e($l) ?></span><?php endforeach; ?></div>
<div class="scroll"><table class="tbl ch"><tr><th>Période</th><?php foreach (CATS as [$l, $c]): ?><th style="color:<?= $c ?>" title="<?= e($l) ?>"><?= e(explode(' ', $l)[0]) ?></th><?php endforeach; ?><th>CA planifié HT</th><th>Occupation des équipes</th></tr>
<?php foreach ($lignes as $L): $taux = $L['capa'] > 0 ? $L['jh'] / $L['capa'] * 100 : 0; $ca = array_sum($L['ca']); ?>
  <tr><td><a href="planning.php?vue=<?= $par === 'semaine' ? 'ressources' : 'mois' ?>&jour=<?= $L['du'] ?>"><?= e($L['lib']) ?></a></td>
  <?php foreach (CATS as $k => [$l, $c]): ?><td><?= $L['n'][$k] ? '<strong>' . $L['n'][$k] . '</strong><br><span class="small">' . money($L['ca'][$k]) . '</span>' : '<span class="muted">–</span>' ?></td><?php endforeach; ?>
  <td><strong><?= money($ca) ?></strong></td>
  <td><div class="barre" title="<?= round($L['jh'], 1) ?> jours-personne occupés sur <?= round($L['capa'], 1) ?> disponibles"><i style="width:<?= min(100, round($taux)) ?>%;background:<?= $taux > 100 ? '#e6332a' : ($taux > 80 ? '#c77a12' : '#8caa31') ?>"></i></div><span class="small"><?= round($taux) ?> % · <?= round($L['jh'], 1) ?>/<?= round($L['capa'], 1) ?> j</span></td></tr>
<?php endforeach; ?></table></div>
<p class="muted small">Le montant d'un chantier compte sur la période où il commence (montant HT du bloc Devis). L'occupation compare les jours-personne affectés aux jours ouvrés disponibles (personnel actif, hors congés, week-ends et jours fériés).</p>
<?php page_end(); ?>
