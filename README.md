ReadMe

tt

Uncaught Error: array_map(): Argument #2 ($array) must be of type array, null given
in C:\Users\enric\Local Sites\entwicklerseite\app\public\wp-content\plugins\FSR-Plugin-main\membercards\templates\frontend-grid.php on line 140

Call stack:

array_map('trim', NULL)
wp-content/plugins/FSR-Plugin-main/membercards/templates/frontend-grid.php:140
include('C:\Users\enric\Local...es\frontend-grid.php')
wp-content/plugins/FSR-Plugin-main/membercards/members.php:518
fsr_members_shortcode_renderer(array, '', 'fsr_members')
wp-includes/shortcodes.php:434
do_shortcode_tag(array)
preg_replace_callback('/\[(\[?)(fsr_members...*+)\[\/\2\])?)(\]?)/', 'do_shortcode_tag', '[fsr_members] ')
wp-includes/shortcodes.php:273
do_shortcode('[fsr_members] ')
wp-includes/class-wp-hook.php:341
WP_Hook::apply_filters('[fsr_members] ', array)
wp-includes/plugin.php:205
apply_filters('the_content', '<!-- wp:shortcode --...-- /wp:shortcode -->')
wp-includes/post-template.php:256
the_content('Continue reading<spa...text"> "Test"</span>')
wp-content/themes/blocksy/inc/components/single/content-helpers.php:258
blocksy_single_content()
wp-content/themes/blocksy/template-parts/single.php:84
require('C:\Users\enric\Local...ate-parts\single.php')
wp-includes/template.php:816
load_template('C:\Users\enric\Local...ate-parts/single.php', false, array)
wp-includes/template.php:749
locate_template(array, true, false, array)
wp-includes/general-template.php:206
get_template_part('template-parts/single')
wp-content/themes/blocksy/single.php:17
require('C:\Users\enric\Local...s\blocksy\single.php')
wp-includes/template.php:816
load_template('C:\Users\enric\Local...s/blocksy/single.php', false, array)
wp-includes/template.php:749
locate_template(array, true, false, array)
wp-includes/general-template.php:206
get_template_part('single')
wp-content/themes/blocksy/page.php:15
include('C:\Users\enric\Local...mes\blocksy\page.php')
wp-includes/template-loader.php:132
require_once('C:\Users\enric\Local...\template-loader.php')
wp-blog-header.php:19
require('C:\Users\enric\Local...c\wp-blog-header.php')
index.php:17
Query Monitor
