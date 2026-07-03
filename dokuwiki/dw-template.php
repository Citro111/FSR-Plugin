<?php
if (!defined('ABSPATH')) exit;

get_header();

$wiki = fsr_dw_current_page();
$title = $wiki['title'] ?? '';
$content = $wiki['content'] ?? '';
?>

<div class="dw-page">
    <div class="dw-hero">
        <a class="dw-back" href="<?php echo home_url('/wiki'); ?>">
            ← Wiki Übersicht
        </a>

        <h1 class="dw-title">
            <?php echo esc_html($title); ?>
        </h1>
    </div>

    <div class="dw-content">
        <?php echo $content; ?>
    </div>

</div>

<?php get_footer(); ?>