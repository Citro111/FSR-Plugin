<?php

if (!defined('ABSPATH')) {
    exit;
}

$layout_settings = is_array($layout_settings ?? null) ? $layout_settings : [];
$desktop_cols = max(1, min(6, absint($layout_settings['desktop_cols'] ?? 4)));
$tablet_cols = max(1, min($desktop_cols, absint($layout_settings['tablet_cols'] ?? 2)));
$mobile_cols = max(1, min($tablet_cols, absint($layout_settings['mobile_cols'] ?? 1)));
$grid_style = sprintf(
    '--fsr-cols-desktop:%d;--fsr-cols-tablet:%d;--fsr-cols-mobile:%d;',
    $desktop_cols,
    $tablet_cols,
    $mobile_cols
);

$teams = [
    FSR_ETIT_TEAM_ELECTED => ['title' => 'Gewählte Mitglieder', 'list' => []],
    FSR_ETIT_TEAM_HELPERS  => ['title' => 'Freie Helfer', 'list' => []],
    FSR_ETIT_TEAM_FORMER   => ['title' => 'Ehemalige', 'list' => []],
];
foreach ($members as $member) {
    $team_id = fsr_etit_member_normalize_team($member['team'] ?? '');
    $teams[$team_id]['list'][] = $member;
}

if (!empty($teams[FSR_ETIT_TEAM_FORMER]['list'])) {
    usort($teams[FSR_ETIT_TEAM_FORMER]['list'], static function ($left, $right): int {
        $year_left = (int) preg_replace('/\D+/', '', (string) ($left['abgang_jahr'] ?? ''));
        $year_right = (int) preg_replace('/\D+/', '', (string) ($right['abgang_jahr'] ?? ''));
        return $year_left !== $year_right
            ? $year_right <=> $year_left
            : (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
    });
}

$requested_team = sanitize_key((string) ($a['team'] ?? 'all'));
if ($requested_team !== 'all' && isset($teams[$requested_team])) {
    $teams = [$requested_team => $teams[$requested_team]];
}

$render_card = static function (array $member, string $team_id, bool $former = false): void {
    $image = !empty($member['image'])
        ? esc_url($member['image'])
        : 'https://www.gravatar.com/avatar/?d=mp&amp;s=150';
    $full_name = trim((string) ($member['first_name'] ?? '') . ' ' . (string) ($member['last_name'] ?? ''));
    $email_prefix = (string) ($member['email_prefix'] ?? '');
    if ($email_prefix === '' && !empty($member['first_name'])) {
        $email_prefix = fsr_etit_lowercase($member['first_name']);
        $email_prefix = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $email_prefix);
        $email_prefix = preg_replace('/[^a-z0-9_.-]/', '', $email_prefix);
    }
    ?>
    <article class="fsr-member-card fsr-team-<?php echo esc_attr($team_id); ?>">
        <div class="fsr-member-image">
            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($full_name); ?>" loading="lazy" decoding="async">
        </div>
        <h3><?php echo esc_html($full_name ?: 'Unbenannt'); ?></h3>
        <?php if (!empty($member['pronomen'])) : ?>
            <p class="fsr-pronomen"><em>(<?php echo esc_html($member['pronomen']); ?>)</em></p>
        <?php endif; ?>
        <?php if (!empty($member['studiengang'])) : ?>
            <p class="fsr-studiengang">
                <?php echo esc_html($member['studiengang']); ?>
                <?php if (!empty($member['abschluss'])) : ?>
                    (<?php echo esc_html($member['abschluss']); ?>)
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($member['amt'])) :
            $tags = array_filter(array_map('trim', explode(',', $member['amt'])));
            $role_order = get_option(FSR_ETIT_OPTION_MEMBER_ROLE_ORDER, FSR_ETIT_DEFAULT_ROLE_ORDER);
            $tags = fsr_etit_sort_tags($tags, is_array($role_order) ? $role_order : FSR_ETIT_DEFAULT_ROLE_ORDER);
            ?>
            <div class="fsr-amt-tags">
                <?php foreach ($tags as $tag) : ?>
                    <span class="fsr-amt-tag"><?php echo esc_html($tag); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($email_prefix !== '') : ?>
            <p class="fsr-email-text"><?php echo esc_html($email_prefix . FSR_ETIT_EMAIL_SUFFIX); ?></p>
        <?php endif; ?>
        <?php if ($former) : ?>
            <div class="fsr-ehemalige-info">
                <?php if (!empty($member['erstes_jahr'])) : ?><div>Dabei seit: <?php echo esc_html($member['erstes_jahr']); ?></div><?php endif; ?>
                <?php if (!empty($member['abgang_jahr'])) : ?><div>Abgegangen im Jahr: <?php echo esc_html($member['abgang_jahr']); ?></div><?php endif; ?>
                <?php if (!empty($member['semester_anzahl'])) : ?><div>Semester im FSR: <?php echo esc_html($member['semester_anzahl']); ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </article>
    <?php
};
?>

<div class="fsr-members">
    <?php foreach ($teams as $team_id => $team_data) :
        if (empty($team_data['list'])) {
            continue;
        }
        ?>
        <section class="fsr-team-section">
            <h2 class="fsr-team-heading"><?php echo esc_html($team_data['title']); ?></h2>

            <?php if ($team_id === FSR_ETIT_TEAM_FORMER) :
                $groups = [];
                foreach ($team_data['list'] as $member) {
                    $year = trim((string) ($member['abgang_jahr'] ?? '')) ?: 'Unbekannt';
                    $groups[$year][] = $member;
                }
                foreach ($groups as $year => $group) :
                    ?>
                    <h3 class="fsr-ehemalige-year"><?php echo esc_html($year); ?></h3>
                    <div class="fsr-members-grid" style="<?php echo esc_attr($grid_style); ?>">
                        <?php foreach ($group as $member) { $render_card($member, $team_id, true); } ?>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="fsr-members-grid" style="<?php echo esc_attr($grid_style); ?>">
                    <?php foreach ($team_data['list'] as $member) { $render_card($member, $team_id); } ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
