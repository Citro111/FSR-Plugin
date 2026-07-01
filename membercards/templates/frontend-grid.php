<?php
if (!defined('ABSPATH')) exit;

$layout_settings = isset($layout_settings) && is_array($layout_settings) ? $layout_settings : [];
$desktop_cols = max(1, min(6, absint($layout_settings['desktop_cols'] ?? 4)));
$tablet_cols = max(1, min($desktop_cols, absint($layout_settings['tablet_cols'] ?? 2)));
$mobile_cols = max(1, min($tablet_cols, absint($layout_settings['mobile_cols'] ?? 1)));

$teams = [
    'gewaehlte' => ['title' => 'Gewählte Mitglieder', 'list' => []],
    'helfer'    => ['title' => 'Freie Helfer', 'list' => []],
    'ehemalige' => ['title' => 'Ehemalige', 'list' => []]
];
foreach ($members as $m) {
    $t_id = $m['team'] ?? 'gewaehlte';
    if (isset($teams[$t_id])) { $teams[$t_id]['list'][] = $m; }
    //if (!empty($m['is_ehemalige'])) { $teams['ehemalige']['list'][] = $m; }
}

if (!empty($teams['ehemalige']['list'])) {
    usort($teams['ehemalige']['list'], function ($a, $b) {
        $year_a = !empty($a['abgang_jahr']) ? (int) preg_replace('/\D+/', '', (string) $a['abgang_jahr']) : 0;
        $year_b = !empty($b['abgang_jahr']) ? (int) preg_replace('/\D+/', '', (string) $b['abgang_jahr']) : 0;

        if ($year_a !== $year_b) {
            return $year_b <=> $year_a;
        }

        $order_a = isset($a['sort_order']) ? (int) $a['sort_order'] : 0;
        $order_b = isset($b['sort_order']) ? (int) $b['sort_order'] : 0;
        return $order_a <=> $order_b;
    });
}

if ($a['team'] !== 'all' && isset($teams[$a['team']])) { 
    $teams = [$a['team'] => $teams[$a['team']]]; 
}

echo '<div class="fsr-teams-container">';
//echo '<div class="fsr-member-intro">';
//echo '<p>Die Mitgliederkarten werden aus einzelnen, strukturiert gespeicherten Einträgen gerendert. So bleiben Import, Pflege und Sortierung deutlich stabiler als bei einem einzigen Options-Array.</p>';
echo '</div>';

foreach ($teams as $team_id => $team_data) {
    if (empty($team_data['list'])) continue;
    
    echo '<div class="fsr-team-section">';
    echo '<h2 class="fsr-team-heading" style="color: var(--theme-palette-color-4);">' . esc_html($team_data['title']) . '</h2>';
    echo '<div class="fsr-members-grid" style="--fsr-cols-desktop:' . esc_attr($desktop_cols) . '; --fsr-cols-tablet:' . esc_attr($tablet_cols) . '; --fsr-cols-mobile:' . esc_attr($mobile_cols) . ';">';
    
    foreach ($team_data['list'] as $m) {
        $img = !empty($m['image']) ? esc_url($m['image']) : 'https://www.gravatar.com/avatar/?d=mp&s=150';
        $full_name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        $team_label = ucfirst($team_id);
        
        $prefix = !empty($m['email_prefix']) ? $m['email_prefix'] : '';
        if (empty($prefix) && !empty($m['first_name'])) {
            $prefix = strtolower($m['first_name']);
            $prefix = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $prefix);
            $prefix = preg_replace('/[^a-z0-9_.-]/', '', $prefix);
        }
        ?>
        <article class="fsr-member-card fsr-team-<?php echo esc_attr($team_id); ?>">
            <div class="fsr-member-image">
                <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($full_name); ?>">
            </div>
            <h3><?php echo esc_html($full_name ?: 'Unbenannt'); ?></h3>
            
            <?php if (!empty($m['pronomen'])): ?>
                <p class="fsr-pronomen"><em>(<?php echo esc_html($m['pronomen']); ?>)</em></p>
            <?php endif; ?>

            <?php if (!empty($m['studiengang'])): 
                $display_studium = esc_html($m['studiengang']);
                if(!empty($m['abschluss'])) { $display_studium .= ' (' . esc_html($m['abschluss']) . ')'; }
            ?>
                <p class="fsr-studiengang"><?php echo $display_studium; ?></p>
            <?php endif; ?>

            <?php if (!empty($m['amt'])): ?>
                <div class="fsr-amt-tags">
                    <?php 
                    $tags = explode(',', $m['amt']);
                    foreach($tags as $tag) {
                        if(trim($tag) !== '') { 
                            echo '<span class="fsr-amt-tag">' . esc_html(trim($tag)) . '</span>'; 
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($prefix)): ?>
                <p class="fsr-email-text">
                    <?php echo esc_html($prefix . ' (at) fsr-etit.de'); ?>
                </p>
            <?php endif; ?>

            <?php if ($team_id === 'ehemalige') : ?>
                <div class="fsr-ehemalige-info">
                    <?php if(!empty($m['erstes_jahr'])): ?><div>Dabei seit: <?php echo esc_html($m['erstes_jahr']); ?></div><?php endif; ?>
                    <?php if(!empty($m['abgang_jahr'])): ?><div>Abgegangen im Jahr: <?php echo esc_html($m['abgang_jahr']); ?></div><?php endif; ?>
                    <?php if(!empty($m['semester_anzahl'])): ?><div>Semester im FSR: <?php echo esc_html($m['semester_anzahl']); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }
    echo '</div>'; 
    echo '</div>'; 
}
echo '</div>';