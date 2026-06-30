<?php
if (!defined('ABSPATH')) exit;

$teams = [
    'gewaehlte' => ['title' => 'Gewählte Mitglieder', 'list' => []],
    'helfer'    => ['title' => 'Freie Helfer', 'list' => []],
    'ehemalige' => ['title' => 'Ehemalige', 'list' => []]
];

foreach ($members as $m) {
    $t_id = $m['team'] ?? 'gewaehlte';
    if (isset($teams[$t_id])) { $teams[$t_id]['list'][] = $m; }
}

if ($a['team'] !== 'all' && isset($teams[$a['team']])) { 
    $teams = [$a['team'] => $teams[$a['team']]]; 
}

echo '<div class="fsr-teams-container">';

foreach ($teams as $team_id => $team_data) {
    if (empty($team_data['list'])) continue;
    
    echo '<div class="fsr-team-section">';
    echo '<h2 class="fsr-team-heading" style="color: var(--theme-palette-color-4);">' . esc_html($team_data['title']) . '</h2>';
    echo '<div class="fsr-members-grid" style="gap: 20px;">';
    
    foreach ($team_data['list'] as $m) {
        $img = !empty($m['image']) ? esc_url($m['image']) : 'https://www.gravatar.com/avatar/?d=mp&s=150';
        $full_name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        
        $prefix = !empty($m['email_prefix']) ? $m['email_prefix'] : '';
        if (empty($prefix) && !empty($m['first_name'])) {
            $prefix = strtolower($m['first_name']);
            $prefix = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $prefix);
            $prefix = preg_replace('/[^a-z0-9_.-]/', '', $prefix);
        }
        ?>
        <div class="fsr-member-card" style="border: 1px solid var(--theme-palette-color-5); background: var(--theme-palette-color-9); box-shadow: 0 4px 6px var(--theme-palette-color-11);">
            <img src="<?php echo $img; ?>" alt="<?php echo esc_attr($full_name); ?>">
            <h3 style="color: var(--theme-palette-color-4);"><?php echo esc_html($full_name); ?></h3>
            
            <?php if (!empty($m['pronomen'])): ?>
                <p class="fsr-pronomen" style="color: var(--theme-palette-color-3);"><em>(<?php echo esc_html($m['pronomen']); ?>)</em></p>
            <?php endif; ?>

            <?php if (!empty($m['studiengang'])): 
                $display_studium = esc_html($m['studiengang']);
                if(!empty($m['abschluss'])) { $display_studium .= ' (' . esc_html($m['abschluss']) . ')'; }
            ?>
                <p class="fsr-studiengang" style="color: var(--theme-palette-color-3);"><?php echo $display_studium; ?></p>
            <?php endif; ?>

            <?php if (!empty($m['amt'])): ?>
                <div class="fsr-amt-tags">
                    <?php 
                    $tags = explode(',', $m['amt']);
                    foreach($tags as $tag) {
                        if(trim($tag) !== '') { 
                            echo '<span class="fsr-amt-tag" style="background: var(--theme-palette-color-13); color: var(--theme-palette-color-3); border: 1px solid var(--theme-palette-color-5);">' . esc_html(trim($tag)) . '</span>'; 
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($prefix)): ?>
                <p class="fsr-email-text" style="color: var(--theme-palette-color-3);">
                    <?php echo esc_html($prefix . ' (at) fsr-etit.de'); ?>
                </p>
            <?php endif; ?>

            <?php if ($team_id === 'ehemalige') : ?>
                <div class="fsr-ehemalige-info" style="border-top: 1px solid var(--theme-palette-color-5); color: var(--theme-palette-color-3);">
                    <?php if(!empty($m['erstes_jahr'])): ?><div>Dabei seit: <?php echo esc_html($m['erstes_jahr']); ?></div><?php endif; ?>
                    <?php if(!empty($m['semester_anzahl'])): ?><div>Semester im FSR: <?php echo esc_html($m['semester_anzahl']); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    echo '</div>'; 
    echo '</div>'; 
}
echo '</div>';