<?php
/** Standalone AJAX authorization regression; every storage/provider operation is mocked. */
define( 'ABSPATH', __DIR__ );
// These isolated fixtures model a Core build with RentEGI support.
function rentfetch_get_floorplan_unit_query_args() {}
function rentfetch_property_syncing_fields_rentegi() {}
class WP_Error {
	private $code; private $message; private $data;
	function __construct( $code, $message, $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->message; }
	function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post_type_object( $type ) { return (object) array( 'cap' => (object) array( 'edit_others_posts' => 'edit_others_posts' ) ); }
function current_user_can( $cap, $id = 0 ) { return 'edit_others_posts' === $cap ? in_array( $GLOBALS['role'], array('editor','administrator'),true ) : ! in_array( (int)$id, $GLOBALS['denied'], true ); }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists($key,$GLOBALS['meta'][$id]??array()); }
function get_posts( $args ) {
	$result=array(); foreach ($GLOBALS['posts'] as $id=>$post) { if($post->post_type!==$args['post_type'])continue; $valid=true;
	foreach($args['meta_query'] as $q){$valid=$valid&&get_post_meta($id,$q['key'],true)===$q['value'];} if($valid)$result[]=$id; }
	return array_slice($result,0,$args['posts_per_page']);
}
function rfs_is_property_configured_for_sync( $source, $id ) { return $GLOBALS['configured'] && 'rentegi'===$source && 'PROP'===$id; }
function absint($value){return abs((int)$value);} function wp_unslash($value){return $value;}
function add_action(...$args){} function check_ajax_referer(...$args){} function rfs_is_sync_locked(){return false;}
function get_transient($key){return false;}
function rfs_sync_single_property($id,$source){++$GLOBALS['provider_calls'];}
class PermissionResponse extends RuntimeException { public $data; public $status; public function __construct($data,$status){$this->data=$data;$this->status=$status;} }
function wp_send_json_error($data,$status=400){throw new PermissionResponse($data,$status);}
function wp_send_json_success($data){throw new PermissionResponse($data,200);}
require dirname(__DIR__).'/lib/admin/manual-sync-permissions.php';
require dirname(__DIR__).'/lib/admin/ajax-sync-single-property.php';
function permission_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$posts=array();$meta=array();$denied=array();$configured=true;$provider_calls=0;
foreach(array(1=>'properties',2=>'floorplans',3=>'units') as $id=>$type){$posts[$id]=(object)array('ID'=>$id,'post_type'=>$type,'post_status'=>'publish');}
$meta=array(1=>array('property_id'=>'PROP','property_source'=>'rentegi'),2=>array('property_id'=>'PROP','floorplan_id'=>'PLAN','floorplan_source'=>'rentegi'),3=>array('property_id'=>'PROP','floorplan_id'=>'PLAN','unit_source'=>'rentegi'));
foreach(array('subscriber','author','editor','administrator') as $role){
 foreach(array(1,2,3) as $id){ $_POST=array('post_id'=>$id,'property_id'=>'PROP','integration'=>'rentegi'); $before=$provider_calls;
 try{rfs_sync_single_property_ajax_handler();}catch(PermissionResponse $r){permission_check((in_array($role,array('editor','administrator'),true)?200:403)===$r->status,'Role enforcement failed:'.$role);}
 permission_check($provider_calls-$before===(in_array($role,array('editor','administrator'),true)?1:0),'Denied requests cannot reach providers');}
}
$role='editor';$before=$provider_calls;
foreach(array(array('post_id'=>3,'property_id'=>'OTHER','integration'=>'rentegi'),array('post_id'=>3,'property_id'=>'PROP','integration'=>'yardi'),array('property_id'=>'PROP','integration'=>'rentegi'),array('post_id'=>999,'property_id'=>'PROP','integration'=>'rentegi'),array('post_id'=>array(3),'property_id'=>'PROP','integration'=>'rentegi')) as $_POST){
 try{rfs_sync_single_property_ajax_handler();throw new RuntimeException('Forged target allowed');}catch(PermissionResponse $r){permission_check(400===$r->status,'Forged target should be invalid');}
}
permission_check($before===$provider_calls,'Forged targets cannot invoke providers');
$_POST=array('post_id'=>3,'property_id'=>'PROP','integration'=>'rentegi');
$denied=array(1);permission_check(is_wp_error(rfs_authorize_manual_sync_request()),'Cannot sync inaccessible parent');$denied=array();
$posts[4]=(object)array('ID'=>4,'post_type'=>'properties','post_status'=>'publish');$meta[4]=$meta[1];
permission_check(is_wp_error(rfs_authorize_manual_sync_request()),'Ambiguous parent must be rejected');unset($posts[4],$meta[4]);
$meta[3]['floorplan_id']='OTHER';permission_check(is_wp_error(rfs_authorize_manual_sync_request()),'Unit must resolve a source-scoped floorplan');$meta[3]['floorplan_id']='PLAN';
unset($meta[3]['unit_source']);$meta[3]['floorplan_source']='rentegi';permission_check(!is_wp_error(rfs_authorize_manual_sync_request()),'Legacy unit source fallback remains supported');
$meta[3]['unit_source']='';permission_check(is_wp_error(rfs_authorize_manual_sync_request()),'Explicit manual source cannot invoke provider sync');
$meta[3]['unit_source']='rentegi';$configured=false;permission_check(is_wp_error(rfs_authorize_manual_sync_request()),'Unconfigured target must be rejected');
echo "Manual-sync permissions passed: role matrix, denied/no-provider requests, forged targets, parent/floorplan ambiguity, legacy source and manual-source exclusion.\n";
