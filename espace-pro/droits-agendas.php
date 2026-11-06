<?php
// Administration › Qui voit qui : pour chaque compte, les agendas qu'il voit et ceux qu'il peut modifier, personne par personne.
// Administrateurs et secrétariat voient et modifient tout. L'administrateur peut confier ce réglage à un autre compte.
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
if (!peut_gerer_droits()) { http_response_code(403); page_start('Accès refusé'); echo '<p>Ce réglage est réservé à l\'administrateur et aux comptes à qui il l\'a confié.</p>'; page_end(); exit; }

$pers = personnel_liste();
$comptes = q("SELECT u.*, (SELECT GROUP_CONCAT(p.id) FROM personnel p WHERE p.utilisateur_id = u.id) AS mes_pers FROM utilisateurs u WHERE u.actif = 1 ORDER BY FIELD(u.role, 'admin', 'secretaire', 'user'), u.prenom, u.nom")->fetchAll();
$droits = []; foreach (q("SELECT * FROM droits_agenda") as $d) $droits[(int)$d['utilisateur_id']][(int)$d['personnel_id']] = $d['niveau'];
$bureau = fn($u) => in_array($u['role'], ['admin', 'secretaire'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'droits') {
    $n = 0;
    foreach ($comptes as $u) {
        $uid = (int)$u['id']; if ($bureau($u) || !isset($_POST['c'][$uid]) || (!est_admin() && $uid === (int)user()['id'])) continue; // on ne règle pas ses propres droits
        $mes = array_map('intval', array_filter(explode(',', (string)$u['mes_pers'])));
        $cel = (array)$_POST['c'][$uid]; $tousM = true; $tousV = true; $lignes = [];
        foreach ($pers as $p) { $pid = (int)$p['id']; $v = (string)($cel[$pid] ?? ''); if (!in_array($v, ['', 'voir', 'modifier'], true)) $v = '';
            if ($v !== 'modifier') $tousM = false; if ($v === '') $tousV = false;
            if (!in_array($pid, $mes, true) && $v !== '') $lignes[$pid] = $v; }
        $propre = $mes ? (string)($cel[$mes[0]] ?? '') : 'voir';
        $droit = $tousM ? 'tout' : ($propre === 'modifier' ? 'perso' : 'lecture');
        q("UPDATE utilisateurs SET planning_droit = ?, planning_vue = ?, gere_droits = ? WHERE id = ?", [$droit, $tousV ? 'tous' : 'liste', est_admin() ? (int)!empty($_POST['g'][$uid]) : (int)$u['gere_droits'], $uid]);
        q("DELETE FROM droits_agenda WHERE utilisateur_id = ?", [$uid]);
        if (!$tousM) foreach ($lignes as $pid => $v) q("INSERT INTO droits_agenda (utilisateur_id, personnel_id, niveau) VALUES (?,?,?)", [$uid, $pid, $v]);
        $n++;
    }
    journal('parametres', 'Droits sur les agendas modifiés (' . $n . ' compte(s))');
    flash('Droits enregistrés : ils s\'appliquent dès le prochain affichage du planning.');
    redirect('droits-agendas.php');
}

// Valeur affichée d'une case : ce que le compte peut faire sur l'agenda de cette personne
$niveau = function (array $u, int $pid) use ($droits, $bureau): string {
    if ($bureau($u) || $u['planning_droit'] === 'tout') return 'modifier';
    $mes = array_map('intval', array_filter(explode(',', (string)$u['mes_pers'])));
    if (in_array($pid, $mes, true)) return $u['planning_droit'] === 'perso' ? 'modifier' : 'voir';
    if (isset($droits[(int)$u['id']][$pid])) return $droits[(int)$u['id']][$pid];
    return ($u['planning_vue'] ?? 'tous') === 'tous' ? 'voir' : '';
};
page_start('Qui voit qui', 'droits-agendas.php');
?>
<style>
.droits td,.droits th{text-align:center;white-space:nowrap}.droits td:first-child,.droits th:first-child{text-align:left;position:sticky;left:0;background:var(--blanc,#fff);z-index:1}
.droits select{width:auto;margin:0;padding:4px 6px;font-size:.85rem;border-radius:8px}.droits select.n-modifier{background:#e3f0da;border-color:#8aa33c}.droits select.n-voir{background:#eef3f8;border-color:#9fb3c8}.droits select.n-{background:#f3f3f1;color:#8a8f96}
.droits .rapide{display:flex;gap:4px;justify-content:flex-start;margin-top:4px}.droits td.moi{box-shadow:inset 0 0 0 2px #e6c88a}.droits .bur{color:#5b6b5f;font-size:.85rem}
</style>
<p class="lead">Pour chaque compte, ce qu'il voit et ce qu'il peut modifier dans le planning, agenda par agenda. <strong>Modifier</strong> : créer, déplacer, changer les rendez-vous de cette personne. <strong>Voir</strong> : consulter seulement. <strong>—</strong> : l'agenda de cette personne n'apparaît pas du tout pour ce compte (planning, Mon planning, téléphone).</p>
<h2>Droits sur les agendas</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="droits">
<div class="scroll"><table class="tbl droits"><thead><tr><th>Compte</th><?php foreach ($pers as $p): ?><th><?= e($p['prenom']) ?><br><span style="font-weight:400;text-transform:none;letter-spacing:0" class="small"><?= e($p['nom']) ?></span></th><?php endforeach; ?><?php if (est_admin()): ?><th title="Ce compte peut ouvrir cette page et régler les droits des autres (sauf ce droit lui-même)">Peut régler<br>ces droits</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($comptes as $u): $uid = (int)$u['id']; $mes = array_map('intval', array_filter(explode(',', (string)$u['mes_pers']))); ?>
<tr><td><strong><?= e(trim($u['prenom'] . ' ' . $u['nom'])) ?></strong><br><span class="small muted"><?= e(role_libelle($u['role'])) ?></span>
  <?php if (!$bureau($u) && (est_admin() || $uid !== (int)user()['id'])): ?><div class="rapide"><button type="button" class="mini" data-tout="modifier">Tout modifier</button><button type="button" class="mini" data-tout="voir">Tout voir</button><button type="button" class="mini" data-tout="perso">Son agenda seul</button></div><?php endif; ?></td>
  <?php foreach ($pers as $p): $pid = (int)$p['id']; $v = $niveau($u, $pid); $moi = in_array($pid, $mes, true); ?>
  <td class="<?= $moi ? 'moi' : '' ?>" title="<?= $moi ? 'Son propre agenda' : '' ?>"><?php if ($bureau($u)): ?><span class="bur">Modifier</span><?php else: ?><select name="c[<?= $uid ?>][<?= $pid ?>]" class="n-<?= $v ?>" data-moi="<?= $moi ? 1 : 0 ?>"><option value="modifier" <?= $v === 'modifier' ? 'selected' : '' ?>>Modifier</option><option value="voir" <?= $v === 'voir' ? 'selected' : '' ?>>Voir</option><?php if (!$moi): ?><option value="" <?= $v === '' ? 'selected' : '' ?>>—</option><?php endif; ?></select><?php endif; ?></td>
  <?php endforeach; ?>
  <?php if (est_admin()): ?><td><?php if ($bureau($u)): ?><span class="bur"><?= $u['role'] === 'admin' ? 'Oui' : '—' ?></span><?php else: ?><input type="checkbox" name="g[<?= $uid ?>]" value="1" <?= (int)$u['gere_droits'] ? 'checked' : '' ?> style="width:auto"><?php endif; ?></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<p><button class="btn">Enregistrer les droits</button></p>
</form>
<p class="small muted">Les administrateurs et le secrétariat voient et modifient tous les agendas (le rôle se change dans <a href="utilisateurs.php">Comptes</a>). La case encadrée est l'agenda de la personne elle-même : elle le voit toujours. Les rendez-vous sans personne affectée ne sont visibles que des comptes qui voient tout le monde.</p>
<script>
document.querySelectorAll('.droits select').forEach(s => s.addEventListener('change', () => s.className = 'n-' + s.value));
document.querySelectorAll('.droits [data-tout]').forEach(b => b.onclick = () => b.closest('tr').querySelectorAll('select').forEach(s => { const t = b.dataset.tout; s.value = t === 'perso' ? (s.dataset.moi === '1' ? 'modifier' : '') : t; s.className = 'n-' + s.value; }));
</script>
<?php page_end(); ?>
