<?php

require_once 'vendor/autoload.php';

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

function oneclickvirtdocker_MetaData()
{
    return ['DisplayName' => 'oneclickvirt docker 对接模块', 'APIVersion' => '1.0.0', 'HelpDoc' => 'https://miniduo.cn'];
}

function oneclickvirtdocker_ConfigOptions()
{
    return [
        ['type' => 'text', 'name' => '用户名',  'default' => '', 'key'  => 'username','description' => 'username'],
        ['type' => 'text', 'name' => '密码',  'default' => '', 'key'  => 'password','description' => 'password']
    ];
}

function oneclickvirtdocker_sftp($params) {
    $public_key = $params['accesshash'];
    $sftp = new SFTP($params['server_ip'], $params['port']);
    if (!$sftp->login($params['server_username'],$params['server_password'])) {
        return null;
    }
    return $sftp;
}

function oneclickvirtdocker_ssh($params) {
    $public_key = $params['accesshash'];
    $ssh = new SSH2($params['server_ip'], $params['port']);
    if (!$ssh->login($params['server_username'],$params['server_password'])) {
        return null;
    }
    return $ssh;
}

function oneclickvirtdocker_TestLink($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    return ['status' => 200, 'data' => ['server_status' => 1]];
}

function oneclickvirtdocker_CreateAccount($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $ssh->setTimeout(300);
    $sftp = oneclickvirtdocker_sftp($params);
    if (!$sftp) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $localScriptPath = __DIR__ . '/docker-main/scripts/onedocker.sh';
    $scriptContent = file_get_contents($localScriptPath);
    $remoteScriptPath = '/tmp/onedocker.sh';
    $sftp->put($remoteScriptPath, $scriptContent);
    $ssh->exec("chmod +x $remoteScriptPath");
    $server_name = $params['domain'];
    $configoptions = $params['configoptions'];

    $cpu = $params['configoptions']['cpu'] ?: '1';
    $memory  = $params['configoptions']['memory'] ?: '128';
    $password = empty($params['password']) ? 'yourpassword' : $params['password'];
    $system = $params['configoptions']['system'] ?: 'alpine';
    $sshport = $params['configoptions']['sshport'] ?: rand(10000, 60000);
    $startport = $sshport + 1;
    $endport = $startport + ($params['configoptions']['ports']?:5);
    $independent_ipv6 = $params['configoptions']['independent_ipv6'] ?: 'n';
    $disk = $params['configoptions']['disk'] ?: '512';

    $output = $ssh->exec("bash $remoteScriptPath $server_name $cpu $memory $password $sshport $startport $endport $independent_ipv6 $system $disk");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        $update['dedicatedip'] = $params['server_ip'];
        $update['domainstatus'] = 'Active';
        $update['username'] = 'root';
        $update['os'] = $system;
        $update['assignedips'] = $startport.'-'.$endport;
        $update['password'] = cmf_encrypt($password);
        $update['domain'] = $params['domain'];
        $update['port'] = $sshport;
        think\Db::name('host')->where('id', $params['hostid'])->update($update);
        return 'success';
    }
    $errors = explode('\n', $output);
    return ['status' => 'error', 'msg' => array_pop($errors) ?? '未知错误'];
}

function oneclickvirtdocker_SuspendAccount($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec("docker stop $server_name");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        return 'success';
    }
    return ['status' => 'error', 'msg' => $output];
}

function oneclickvirtdocker_Reboot($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec("docker restart $server_name");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        return 'success';
    }
    return ['status' => 'error', 'msg' => $output];
}

function oneclickvirtdocker_UnsuspendAccount($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec("docker start $server_name");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        return 'success';
    }
    return ['status' => 'error', 'msg' => $output];
}

function oneclickvirtdocker_TerminateAccount($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec("docker rm -f $server_name");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        return 'success';
    }
    return ['status' => 'error', 'msg' => $output];
}

function oneclickvirtdocker_Renew($params)
{
    $res = oneclickvirtdocker_unsuspendaccount($params);
    if ($res == 'success') {
        return ['status' => 'success', 'msg' => '续费成功'];
    }
    return ['status' => 'error', 'msg' => '续费失败'];
}

function oneclickvirtdocker_Status($params)
{
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec("docker inspect --format='{{.State.Status}}' $server_name");
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        $result['status'] = 'success';
        if ('running' == trim($output)) {
            $result['data']['status'] = 'on';
            $result['data']['des'] = '运行中';
        } else {
            $result['data']['status'] = 'off';
            $result['data']['des'] = '暂停中';
        }
        return $result;
    }
    $result['data']['status'] = 'unknown';
    $result['data']['des'] = '未知';
    return $result;
}

function oneclickvirtdocker_gethostinfo($params) {
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
}

function oneclickvirtdocker_CrackPassword($params, $new_pass)
{  
    
    $ssh = oneclickvirtdocker_ssh($params);
    if (!$ssh) {
        return ['status' => 500, 'data' => ['msg' => '认证失败', 'server_status' => 0]];
    }
    $server_name = $params['domain'];
    $output = $ssh->exec(sprintf("docker exec %s %s -c 'echo \"root:%s\" | chpasswd'", $server_name, 'sh', $new_pass));
    $status = $ssh->getExitStatus();
    if ($status == 0) {
        return ['status' => 'success', 'msg' => '密码重置成功'];
    }
    return ['status' => 'error', 'msg' => $output ?: '密码重置失败'];
}

function oneclickvirtdocker_vnc($params)
{
    $containerName = is_array($params['domain']) ? $params['domain'][0] : $params['domain'];
    $host = \think\Db::name('host')->where('id', $params['hostid'])->find();

    $dedicatedip = $params['dedicatedip'];
    $port = $host['port'];
    $username = $params['username'];
    $password = $params['password'];
    
    return [
        'status' => 'success',
        'url' => "https://ssheasy.com/connect?host=$dedicatedip&port=$port&user=$username&password=$password"
    ];
    
}

function oneclickvirtdocker_ClientArea($params)
{
    return ['index' => ['name' => '主机信息']];
}

function oneclickvirtdocker_ClientAreaOutput($params, $key)
{
    $result = oneclickvirtdocker_vnc($params);
    
    $host = \think\Db::name('host')->where('id', $params['hostid'])->find();
    \think\facade\Log::write($host);
    if ($key == 'index') {
        return ['template' => 'templates/information.html', 'vars' => ['params' => $params, 'url'=>$result['url'], 'info' => $host]];
    }
    if ($key == 'tips') {
        return ['template' => 'templates/tips.html', 'vars' => ['params' => $params,'url'=> $result['url'],  'info' => $host]];
    }
}
