<?php
/** Actual WordPress deletion/configuration regression; disposable follow-up site only. */
if ( ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) { throw new RuntimeException('Disposable follow-up site required.'); }
$base=dirname(__DIR__);
require_once dirname($base).'/rentfetch/rentfetch.php';
require_once $base.'/lib/api/configured-properties.php';
require_once $base.'/lib/api/property-sync-lock.php';
require_once $base.'/lib/api/sync-access.php';
require_once $base.'/lib/api/entrata/delete-properties-no-longer-in-box-entrata.php';
require_once $base.'/lib/api/yardi/v2/delete-properties-no-longer-in-box-v2.php';
require_once $base.'/lib/api/engrain/update-wp-engrain.php';
require_once $base.'/lib/admin/sync-options.php';
require_once $base.'/lib/api/rentegi/get-api-data-rentegi.php';
require_once $base.'/lib/api/rentegi/delete-properties-no-longer-in-box-rentegi.php';
function configured_check($condition,$message){if(!$condition){throw new RuntimeException($message);}}
foreach(array('properties','floorplans','units')as$type){register_post_type($type,array('public'=>true));}
$run=wp_generate_uuid4();$ids=array();$original=array();
$scope=static function($query)use($run){
 if(in_array($query->get('post_type'),array('properties','floorplans','units'),true)){
  $meta=$query->get('meta_query');$meta=is_array($meta)?$meta:array();$meta[]=array('key'=>'cleanup_fixture_run','value'=>$run);$query->set('meta_query',$meta);
 }
};
add_action('pre_get_posts',$scope);
$keys=array('yardi'=>'rentfetch_options_yardi_integration_creds_yardi_property_code','entrata'=>'rentfetch_options_entrata_integration_creds_entrata_property_ids','engrain'=>'rentfetch_options_engrain_integration_creds_engrain_asset_ids','rentegi'=>'rentfetch_options_rentegi_property_codes');
$callbacks=array('yardi'=>'rfs_yardi_delete_orphans_properties_floorplans_units','entrata'=>'rfs_entrata_delete_orphans_properties_floorplans_units','engrain'=>'rfs_engrain_delete_unconfigured_assets','rentegi'=>'rfs_rentegi_delete_unconfigured_properties');
$missing='missing-'.wp_generate_uuid4();
try{
 foreach($keys as$source=>$key){
  $original[$key]=get_option($key,$missing);update_option($key,'A,B');$records=array();
  foreach(array('A','B','C')as$code){foreach(array('properties'=>'property_source','floorplans'=>'floorplan_source','units'=>'unit_source')as$type=>$source_key){
   $id=wp_insert_post(array('post_type'=>$type,'post_status'=>'publish','post_title'=>'Fixture '.$source.' '.$code,'meta_input'=>array($source_key=>$source,'property_id'=>$code,'cleanup_fixture_run'=>$run)),true);
   configured_check(!is_wp_error($id),'Fixture insertion failed.');$records[$code][]=$id;$ids[]=$id;
  }}
  $callbacks[$source](array('A'));
  foreach($records['B']as$id){configured_check(null!==get_post($id),'Old queued list deleted newly configured B.');}
  foreach($records['C']as$id){configured_check(null===get_post($id),'Actually removed C must still be deleted.');}
  // Candidate chosen before an administrator adds it must be rechecked under lock.
  $id=wp_insert_post(array('post_type'=>'units','post_status'=>'publish','post_title'=>'Added during cleanup','meta_input'=>array('unit_source'=>$source,'property_id'=>'C','cleanup_fixture_run'=>$run)),true);$ids[]=$id;
  $changed=false;
  $change=static function($sql)use(&$changed,$key){if(!$changed&&str_contains($sql,'GET_LOCK')){$changed=true;update_option($key,'A,B,C');}return$sql;};
  add_filter('query',$change);
  try{$callbacks[$source](array('A'));}finally{remove_filter('query',$change);}
  configured_check($changed&&null!==get_post($id),'Configuration added after selection must be preserved under lock.');
 }
 // A different PHP worker updates the database without refreshing this request's option cache.
 $key=$keys['rentegi']; update_option($key,'A'); rfs_rentegi_get_property_codes();
 $id=wp_insert_post(array('post_type'=>'units','post_status'=>'publish','post_title'=>'Added by another worker','meta_input'=>array('unit_source'=>'rentegi','property_id'=>'D','cleanup_fixture_run'=>$run)),true);$ids[]=$id;
 $changed=false;
 $change=static function($sql)use(&$changed,$key){
  if(!$changed&&str_contains($sql,'GET_LOCK')){
   $changed=true;
   $process=proc_open(array('wp','--path='.ABSPATH,'--skip-plugins','--skip-themes','option','update',$key,'A,D'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
   configured_check(is_resource($process),'Could not start independent option worker.');
   $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
   configured_check(0===proc_close($process),'Independent option update failed: '.$out.$err);
  }return$sql;
 };
 add_filter('query',$change);
 try{rfs_rentegi_delete_unconfigured_properties();}finally{remove_filter('query',$change);}
 configured_check($changed&&null!==get_post($id),'A separate worker added D, but cached configuration allowed its deletion.');
 // A record selected as synced may be converted to manual before cleanup gets its lock.
 $id=wp_insert_post(array('post_type'=>'units','post_status'=>'publish','post_title'=>'Converted to manual','meta_input'=>array('unit_source'=>'rentegi','property_id'=>'E','cleanup_fixture_run'=>$run)),true);$ids[]=$id;
 $changed=false;
 $change=static function($sql)use(&$changed,$id){if(!$changed&&str_contains($sql,'GET_LOCK')){$changed=true;update_post_meta($id,'unit_source','');}return$sql;};
 add_filter('query',$change);
 try{rfs_rentegi_delete_unconfigured_properties();}finally{remove_filter('query',$change);}
 configured_check($changed&&null!==get_post($id),'Cleanup deleted a record whose ownership changed to manual.');
 echo "Current-config cleanup passed for Yardi, Entrata, Engrain and RentEGI, including an independent settings worker and ownership changing before lock acquisition.\n";
}finally{
 remove_action('pre_get_posts',$scope);
 foreach($ids as$id){if(get_post($id)){wp_delete_post($id,true);}}
 foreach($original as$key=>$value){if($value===$missing){delete_option($key);}else{update_option($key,$value);}}
}
