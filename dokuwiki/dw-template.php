<?php
if (!defined('ABSPATH')) exit;
get_header();
$page = get_query_var('dw_page');
$content = fsr_dw_fetch($page); // Ruft die gekapselte Funktion auf
?>
<div class="ct-container-full" data-content="normal" data-vertical-spacing="top:bottom">
    <article>
        <div class="entry-content is-layout-constrained">
            <div class="dw-content">
                <?php 
                if ($content !== false) {
                    echo $content;
                } else {
                    echo "<p>Fehler beim Laden der Wiki-Inhalte.</p>";
                }
                ?>
            </div>
        </div>
    </article>
</div>
<?php
get_footer();