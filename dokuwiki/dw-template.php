<?php
if (!defined('ABSPATH')) exit;

get_header();

$wiki = fsr_dw_current_page();
$title = $wiki['title'] ?? '';
$content = $wiki['content'] ?? '';
?>

<div class="dw-page">
    <div class="dw-hero">
        <h1 class="dw-title" <?php echo esc_html($title); ?> href="<?php echo home_url('/wiki'); ?>">
        </h1>
    </div>

    <div class="dw-content">
        <?php echo $content; ?>
    </div>

</div>

<?php get_footer(); ?>