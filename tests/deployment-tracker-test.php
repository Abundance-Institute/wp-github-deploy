<?php
// Pure unit tests: no WordPress bootstrap, network, or database.
define('ABSPATH', __DIR__);
$GLOBALS['options'] = []; $GLOBALS['events'] = []; $GLOBALS['clock'] = time();
function add_action(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) return false; return update_option($key, $value); }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function wp_clear_scheduled_hook($hook, $args = []) { unset($GLOBALS['events'][$hook . json_encode($args)]); }
function wp_schedule_single_event($at, $hook, $args = [], $error = false) { $GLOBALS['events'][$hook . json_encode($args)] = $at; return true; }
function wp_generate_uuid4() { static $id = 0; return 'test-' . ++$id; }
function is_wp_error($value) { return false; }
class WPGD_Settings { public $history = []; function add_to_history($item) { $this->history[] = $item; } }
class WPGD_GitHub_API {
 public $dispatches = []; public $accept = true; public $readSuccess = true; public $run = null;
 function trigger_workflow($inputs) { $this->dispatches[] = $inputs; return ['success'=>$this->accept,'message'=>'test']; }
 function find_tracked_run($id) { return ['success'=>$this->readSuccess,'data'=>$this->run]; }
 function get_tracked_run($id) { return ['success'=>$this->readSuccess,'data'=>$this->run]; }
 function clear_status_cache() {}
}
require __DIR__ . '/../includes/class-deployment-tracker.php';
function check($value, $message) { if (!$value) throw new Exception($message); echo "PASS $message\n"; }
function setup_case() { $GLOBALS['options']=[]; $GLOBALS['events']=[]; $api=new WPGD_GitHub_API(); $settings=new WPGD_Settings(); return [$api,$settings,new WPGD_Deployment_Tracker($api,$settings)]; }
function job_key() { foreach(array_keys($GLOBALS['options']) as $key) if(str_starts_with($key,'wpgd_job_') && !str_ends_with($key,'_lock')) return $key; throw new Exception('Missing durable job'); }
[$api,$settings,$tracker]=setup_case();
$tracker->dispatch('scheduled_post_published', ['post_id'=>1958]); $key=job_key(); $id=$GLOBALS['options'][$key]['id'];
check(count($GLOBALS['events'])===1, 'accepted dispatch retains a monitoring event');
check($settings->history[0]['phase']==='dispatch','dispatch acceptance is distinct from build completion');
$api->run=['id'=>42,'status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/test/42'];
$tracker->process($id);
check(!isset($GLOBALS['options'][$key]),'successful build clears its durable job');
check(end($settings->history)['phase']==='completed','successful build records completion');
[$api,$settings,$tracker]=setup_case(); $api->accept=false;
$tracker->dispatch('scheduled_post_published',[]); $key=job_key(); $id=$GLOBALS['options'][$key]['id'];
check(isset($GLOBALS['options'][$key]),'rejected dispatch does not lose the deployment');
$GLOBALS['options'][$key]['dispatched_at']=time()-301;
$tracker->process($id);
check($GLOBALS['options'][$key]['state']==='retry','missing run schedules a retry');
$GLOBALS['options'][$key]['retry_at']=time()-1; $api->accept=true; $tracker->process($id);
check(count($api->dispatches)===2,'due retry sends a new dispatch');
check($api->dispatches[0]['deployment_id']!==$api->dispatches[1]['deployment_id'],'retry has its own run correlation ID');
$api->run=['id'=>43,'status'=>'completed','conclusion'=>'failure','html_url'=>'https://github.com/test/43'];
$tracker->process($id);
check($GLOBALS['options'][$key]['state']==='retry','failed build schedules a bounded retry');
$GLOBALS['options'][$key]['attempt']=2; $GLOBALS['options'][$key]['state']='waiting'; $tracker->process($id);
check(!isset($GLOBALS['options'][$key]) && get_option('wpgd_deployment_error'),'exhausted retries raise a persistent admin error');
[$api,$settings,$tracker]=setup_case();$tracker->dispatch('manual',[]);$key=job_key();$id=$GLOBALS['options'][$key]['id'];$api->readSuccess=false;$tracker->process($id);
check(count($api->dispatches)===1,'status outage cannot cause duplicate deployment');
