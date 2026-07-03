<?php
if (!defined('ABSPATH')) exit;
get_header();
$page = get_query_var('dw_page');
$content = fsr_dw_fetch($page); // Ruft die gekapselte Funktion auf
?>
<div class="container">
    <div class="dw-wrapper site-main">
        <div class="dw-content entry-content">
            <?php 
            if ($content !== false) {
                echo $content;
            } else {
                echo "<p>Fehler beim Laden der Wiki-Inhalte.</p>";
            }
            ?>
        </div>
    </div>
</div>
<?php
get_footer();