<?php
/** Executable PHP rendering regression tests for Dendrila Calendar's admin service list. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
$catalog = include dirname( __DIR__ ) . '/languages/dendrila-calendar-fr_FR.l10n.php';
$GLOBALS['tr'] = $catalog['messages'];
$GLOBALS['posts_fixture'] = array();
$GLOBALS['meta_fixture'] = array();
$GLOBALS['wc_fixture'] = array();
$GLOBALS['can_edit'] = array();
$GLOBALS['can_manage'] = true;
$GLOBALS['profile_enabled'] = array();
$GLOBALS['rule_enabled'] = array();
$GLOBALS['woo_enabled'] = true;
function __( $s, $domain = '' ) { return $GLOBALS['tr'][ $s ] ?? $s; }
function esc_html__( $s, $d = '' ) { return esc_html( __( $s, $d ) ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( __( $s, $d ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function wp_kses_post( $s ) { return strip_tags( $s, '<span><del><ins><bdi><small><strong><sup>' ); }
function absint( $s ) { return abs( (int) $s ); }
function wp_unslash( $s ) { return $s; }
function current_user_can( $role, $id = null ) {
    if ( $role === 'manage_options' ) { return $GLOBALS['can_manage']; }
    if ( $role === 'edit_post' ) { return ! empty( $GLOBALS['can_edit'][ $id ] ); }
    return false;
}
function wp_die( $s ) { throw new RuntimeException( $s ); }
function admin_url( $path = '' ) { return 'https://example.org/wp-admin/' . $path; }
function get_edit_post_link( $id, $context = '' ) { return admin_url( 'post.php?post=' . $id . '&action=edit' ); }
function add_query_arg( $k, $v, $url ) { return $url . ( strpos($url, '?') !== false ? '&' : '?' ) . rawurlencode($k) . '=' . rawurlencode((string)$v); }
function get_post( $id ) { return $GLOBALS['posts_fixture'][ $id ] ?? null; }
function get_post_type( $id ) { return get_post( $id )->post_type ?? false; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta_fixture'][$id][$key] ?? ''; }
function get_posts( $args ) {
    return array_values( array_filter($GLOBALS['posts_fixture'], static function($post) use ($args) {
        return $post->post_type === $args['post_type'] && in_array($post->post_status, (array) ($args['post_status'] ?? 'publish'), true);
    }));
}
class WP_Query {
    public $posts; public $found_posts; public $max_num_pages;
    public function __construct($args) {
        $all=get_posts($args);
        usort($all, static function($a,$b) { return strcmp($a->post_title,$b->post_title); });
        $this->found_posts=count($all);
        $size=$args['posts_per_page'];
        $this->max_num_pages=(int) ceil($this->found_posts/$size);
        $this->posts=array_slice($all,($args['paged']-1)*$size,$size);
    }
}
class Dendrila_Calendar_Woo {
    public static function available() { return $GLOBALS['woo_enabled']; }
}
class Dendrila_Calendar_Woo_Service { const ENABLED_KEY = '_dendrila_calendar_booking_product'; }
class Dendrila_Calendar_Schedule {
    public static function rules($id) { return array('enabled'=>!empty($GLOBALS['rule_enabled'][$id])); }
    public static function profile($id) { return array('enabled'=>!empty($GLOBALS['profile_enabled'][$id])); }
}
class FakeProduct {
    public $id,$name,$price,$status,$virtual,$purchasable;
    public function __construct($id,$name,$price,$status='publish',$virtual=true,$purchasable=true){
        $this->id=$id;$this->name=$name;$this->price=$price;$this->status=$status;$this->virtual=$virtual;$this->purchasable=$purchasable;
    }
    public function exists(){return true;}
    public function get_status(){return $this->status;}
    public function get_name(){return $this->name;}
    public function get_price(){return $this->price;}
    public function get_price_html(){return '<del>100,00 €</del> <ins>' . $this->price . ',00 €</ins>';}
    public function is_purchasable(){return $this->purchasable;}
    public function is_virtual(){return $this->virtual;}
    public function is_in_stock(){return true;}
    public function is_on_backorder($qty=1){return false;}
    public function get_meta($k,$single=true){return 'yes';}
}
function wc_get_product($id){return $GLOBALS['wc_fixture'][$id] ?? false;}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-native.php';
function post_fixture( $id, $type, $title, $status = 'publish' ) { $GLOBALS['posts_fixture'][$id] = (object) array('ID'=>$id,'post_type'=>$type,'post_title'=>$title,'post_status'=>$status); }
function meta_fixture( $id, $vals ) { $GLOBALS['meta_fixture'][$id] = $vals; }
function services_html(){ ob_start(); Dendrila_Calendar_Native::render_services(); return ob_get_clean(); }
function check( $condition, $message ) { if (!$condition) throw new RuntimeException('FAILED: '.$message); echo "PASS: $message\n"; }

// Empty state and no published profile.
$html=services_html();
check(strpos($html,'Aucune prestation pour le moment.')!==false,'empty state explains next step');
check(strpos($html,'Aucun profil publié')!==false,'no profile keeps bookings closed');
check(strpos($html,'Vos prestations')!==false,'service list is on landing page');

// Published owner, free active service, real edit link.
post_fixture(10,'dendrila_profile','Émilie');
$GLOBALS['profile_enabled'][10]=true;
post_fixture(11,'dendrila_service','Consultation découverte');
meta_fixture(11, array('_dendrila_booking_profile_id'=>10,'_dendrila_duration_minutes'=>30,'_dendrila_price_cents'=>0));
$GLOBALS['rule_enabled'][11]=true;
$GLOBALS['can_edit'][11]=true;
$html=services_html();
check(strpos($html,'Consultation découverte')!==false && strpos($html,'Gratuit')!==false,'native service is truly free');
check(strpos($html,'Active')!==false && strpos($html,'Modifier Consultation découverte')!==false,'active native service can be edited');

// Woo sale: product is source of truth for name, regular / promotional prices and editing.
post_fixture(12,'dendrila_service','Vieux titre');
post_fixture(120,'product','Produit Woo');
meta_fixture(12, array('_dendrila_booking_profile_id'=>10,'_dendrila_duration_minutes'=>45,'_dendrila_woo_product_id'=>120));
$GLOBALS['rule_enabled'][12]=true;
$GLOBALS['wc_fixture'][120]=new FakeProduct(120,'Coaching en promotion','80');
$GLOBALS['can_edit'][120]=true;
$html=services_html();
check(strpos($html,'Coaching en promotion')!==false && strpos($html,'Vieux titre')===false,'Woo product title is authoritative');
check(strpos($html,'<del>100,00 €</del> <ins>80,00 €</ins>')!==false,'regular and sale prices retained');
check(strpos($html,'post=120&amp;action=edit&amp;dendrila_edit_service=1')!==false,'one click to Woo editor and appointment tab intent');
check(substr_count($html,'class="dendrila-service-card"')===2,'no double counting for linked service/product');

// An orphaned product stays visible with warning and unknown price, even if profile is missing.
post_fixture(13,'dendrila_service','Ancienne prestation');
meta_fixture(13,array('_dendrila_booking_profile_id'=>999,'_dendrila_duration_minutes'=>25,'_dendrila_woo_product_id'=>888));
$GLOBALS['can_edit'][888]=true;
$html=services_html();
check(strpos($html,'Ancienne prestation')!==false && strpos($html,'Tarif indisponible')!==false,'orphan stays visible without faking free price');
check(strpos($html,'Le produit WooCommerce associé est indisponible')!==false,'orphan warning is visible');
check(strpos($html,'Aucun profil publié. Les réservations restent fermées')!==false,'profile warning also visible');
check(strpos($html,'post=888')===false,'missing Woo product cannot be edited through a broken link');

// No edit rights: hide Modify without hiding the service itself.
$GLOBALS['can_edit'][120]=false;
$GLOBALS['can_edit'][11]=false;
$html=services_html();
check(strpos($html,'Coaching en promotion')!==false && strpos($html,'post=120')===false,'unauthorized product edit hidden');
check(strpos($html,'Consultation découverte')!==false && strpos($html,'post=11')===false,'unauthorized native edit hidden');

// Disabled status is distinct from being free or sold out.
post_fixture(14,'dendrila_service','Prestation désactivée','draft');
meta_fixture(14,array('_dendrila_booking_profile_id'=>10,'_dendrila_duration_minutes'=>60));
$GLOBALS['rule_enabled'][14]=true;
$html=services_html();
check(strpos($html,'Désactivée — réservations fermées')!==false,'disabled state explicit');
check(strpos($html,'ne garantit pas de créneau libre')!==false,'active does not imply slot available');

// Long list includes pagination; a query-string parameter cannot inject HTML.
for($id=200;$id<226;$id++){ post_fixture($id,'dendrila_service','Prestation '. $id,'draft');meta_fixture($id,array('_dendrila_booking_profile_id'=>10,'_dendrila_duration_minutes'=>30)); }
$html=services_html();
check(strpos($html,'Page 1 sur 2')!==false && strpos($html,'Page suivante')!==false,'long list paginates');
$_GET['dc_services_page']='2<script>alert(1)</script>';
$html=services_html();
check(strpos($html,'Page précédente')!==false && strpos($html,'<script>')===false,'pagination sanitization');

// Escaping of untrusted names.
post_fixture(909,'dendrila_service','<img src=x onerror=alert(1)>','publish');
meta_fixture(909,array('_dendrila_booking_profile_id'=>10,'_dendrila_duration_minutes'=>30));
$_GET['dc_services_page']='1';
$html=services_html();
check(strpos($html,'onerror=alert(1)&gt;')!==false && strpos($html,'<img src=x')===false,'HTML-escaped service name');

echo "Service-list rendering regression scenarios complete.\n";
