<?php
/** WP-CLI eval-file <file> setup|check, fresh follow-up Cove site only. */
if ( ! str_contains( ABSPATH, '/rentfetch-followup-test-' ) ) { throw new RuntimeException('Disposable follow-up site required.'); }
$mode=$args[0]??'';
$hooks=array('rfs_do_sync','rfs_yardi_do_delete_orphans','rfs_entrata_do_delete_orphans','rfs_engrain_do_delete_orphans','rfs_rentegi_do_delete_orphans','rfs_accelerated_sync_step','rfs_finalize_accelerated_sync_item','rfs_queue_accelerated_sync_run','rfs_record_sync_cache_completion','rfs_delete_synced_data_step');
if('setup'===$mode){
 if(!function_exists('rfs_deactivate_actions')){throw new RuntimeException('Activate Core and Sync before setup.');}
 update_option('rfs_sync_action_args_version',rfs_get_sync_action_args_version());
 update_option('rentfetch_options_enabled_integrations',array());
 update_option('rentfetch_options_data_sync','updatesync');
 foreach($hooks as $hook){as_schedule_single_action(time()+3600,$hook,array('deact-fixture'),'rentfetch-deactivation-fixture');wp_schedule_single_event(time()+3600,$hook,array('deact-fixture'));}
 rfs_update_accelerated_sync_state(array('run_id'=>'deact-fixture','status'=>'running','active_items'=>array()));
 update_option('rfs_delete_synced_data_state',array('status'=>'running'));
 $id=wp_insert_post(array('post_type'=>'properties','post_title'=>'Deactivation preserves inventory','post_status'=>'publish','post_content'=>'Preserve manual content'),true);
 if(is_wp_error($id)){throw new RuntimeException('Could not create preserved fixture.');}
 update_option('rfs_deactivation_fixture_post_id',$id);
 echo "Queued all owned AS/WP-Cron hooks and a preserved-content fixture.\n";
}elseif('check'===$mode){
 global $wpdb;
 foreach($hooks as $hook){
  if(wp_next_scheduled($hook,array('deact-fixture'))){throw new RuntimeException('WP-Cron hook survived: '.$hook);}
  $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=%s AND status='pending'",$hook));
  if($pending){throw new RuntimeException('AS hook survived: '.$hook);}
 }
 if('cancelled'!==(get_option('rfs_accelerated_sync_state')['status']??'')||'cancelled'!==(get_option('rfs_delete_synced_data_state')['status']??'')){throw new RuntimeException('Running state was not cancelled.');}
 $post=get_post((int)get_option('rfs_deactivation_fixture_post_id'));
 if(!$post||'Preserve manual content'!==$post->post_content){throw new RuntimeException('Deactivation changed content.');}
 if(!get_option('rfs_sync_deactivated')){throw new RuntimeException('In-flight retry guard missing.');}
 update_option('rentfetch_options_data_sync','nosync');
 echo "Actual deactivation passed: all owned AS/WP-Cron hooks cancelled, run states cancelled, content preserved, late-worker guard set.\n";
}else{throw new RuntimeException('Use setup or check.');}
