<?php
/**
 * 放置用户登陆注册
 */
namespace Ucenter\Controller;


use Think\Controller;
use User\Api\UserApi;

require_once APP_PATH . 'User/Conf/config.php';

/**
 * 用户控制器
 * 包括用户中心，用户登录及注册
 */
class MemberController extends Controller
{

    /**
     * register  注册页面
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function register()
    {
        //获取参数
        $aUsername = $username = I('post.username', '', 'op_t');
        $aNickname = I('post.nickname', '', 'op_t');
        $aPassword = I('post.password', '', 'op_t');
        $aVerify = I('post.verify', '', 'op_t');
        $aRegVerify = I('post.reg_verify', 0, 'intval');
        $aRegType = I('post.reg_type', '', 'op_t');
        $aStep = I('get.step', 'start', 'op_t');


        if (!modC('REG_SWITCH', '', 'USERCONFIG')) {
            $this->error('注册已关闭');
        }
        if (IS_POST) { //注册用户

            /* 检测验证码 */
            if (C('VERIFY_OPEN') == 1 or C('VERIFY_OPEN') == 2) {
                if (!check_verify($aVerify)) {
                    $this->error('验证码输入错误。');
                }
            }

            if ($aRegType == 'mobile' || (modC('EMAIL_VERIFY_TYPE', 0, 'USERCONFIG') == 2 && $aRegType == 'email')) {
                if (!D('Verify')->checkVerify($aUsername, $aRegType, $aRegVerify, 0)) {
                    $str = $aRegType == 'mobile' ? '手机' : '邮箱';
                    $this->error($str . '验证失败');
                }
            }
            $aUnType = 0;
            //获取注册类型
            check_username($aUsername, $email, $mobile, $aUnType);
            if ($aRegType == 'email' && $aUnType != 2) {
                $this->error('邮箱格式不正确');
            }
            if ($aRegType == 'mobile' && $aUnType != 3) {
                $this->error('手机格式不正确');
            }

            if (!check_reg_type($aUnType)) {
                $this->error('该类型未开放注册。');
            }
            /* 注册用户 */
            $uid = D('User/UcenterMember')->register($aUsername, $aNickname, $aPassword, $email, $mobile, $aUnType);
            if (0 < $uid) { //注册成功
                if (modC('EMAIL_VERIFY_TYPE', 0, 'USERCONFIG') == 1 && $aUnType == 2) {
                    set_user_status($uid, 3);
                    $verify = D('Verify')->addVerify($email, 'email', $uid);
                    $res = $this->sendActivateEmail($email, $verify, $uid); //发送激活邮件
                    // $this->success('注册成功，请登录邮箱进行激活');
                }

                $uid = D('User/UcenterMember')->login($username, $aPassword, $aUnType); //通过账号密码取到uid
                D('Member')->login($uid, false); //登陆
                $this->success('', U('Ucenter/member/step', array('step' => get_next_step('start'))));
            } else { //注册失败，显示错误信息
                $this->error($this->showRegError($uid));
            }
        } else { //显示注册表单
            if (is_login()) {
                redirect(U('Weibo/Index/index'));
            }
            $aType = I('get.type', '', 'op_t');
            $regSwitch = modC('REG_SWITCH', '', 'USERCONFIG');
            $regSwitch = explode(',', $regSwitch);
            $this->assign('regSwitch', $regSwitch);
            $this->assign('step', $aStep);
            $this->assign('type', $aType == '' ? 'username' : $aType);
            $this->display();
        }
    }


    public function step()
    {
        $aStep = I('get.step', '', 'op_t');
        $aUid = session('temp_login_uid');
        M('UcenterMember')->where('id=' . $aUid)->setField('step', $aStep);
        if ($aStep == 'finish') {
            D('Member')->login($aUid, false);

        }
        $this->assign('step', $aStep);
        $this->display('register');
    }


    /* 登录页面 */
    public function login()
    {
        $this->setTitle('用户登录');

        $aUsername = $username = I('post.username', '', 'op_t');
        $aPassword = I('post.password', '', 'op_t');
        $aVerify = I('post.verify', '', 'op_t');
        $aRemember = I('post.remember', 0, 'intval');


        if (IS_POST) { //登录验证
            /* 检测验证码 */
            if (C('VERIFY_OPEN') == 1 or C('VERIFY_OPEN') == 3) {
                if (!check_verify($aVerify)) {
                    $this->error('验证码输入错误。');
                }
            }

            /* 调用UC登录接口登录 */
            check_username($aUsername, $email, $mobile, $aUnType);

            if (!check_reg_type($aUnType)) {
                $this->error('该类型未开放登录。');
            }

            $uid = D('User/UcenterMember')->login($username, $aPassword, $aUnType);
            if (0 < $uid) { //UC登录成功
                /* 登录用户 */
                $Member = D('Member');

                if ($Member->login($uid, $aRemember == 'on')) { //登录用户
                    //TODO:跳转到登录前页面

                    if (UC_SYNC && $uid != 1) {
                        //同步登录到UC
                        $ref = M('ucenter_user_link')->where(array('uid' => $uid))->find();
                        $html = '';
                        $html = uc_user_synlogin($ref['uc_uid']);
                    }


                    $this->success($html, get_nav_url(C('AFTER_LOGIN_JUMP_URL')));
                } else {
                    $this->error($Member->getError());
                }

            } else { //登录失败
                switch ($uid) {
                    case -1:
                        $error = '用户不存在或被禁用！';
                        break; //系统级别禁用
                    case -2:
                        $error = '密码错误！';
                        break;
                    default:
                        $error = $uid;
                        break; // 0-接口参数错误（调试阶段使用）
                }
                $this->error($error);
            }

        } else { //显示登录表单
            if (is_login()) {
                redirect(U('Home/Index/index'));
            }

            $ph = array();
            check_reg_type('username') && $ph[] = '用户名';
            check_reg_type('email') && $ph[] = '邮箱';
            check_reg_type('mobile') && $ph[] = '手机号';
            $this->assign('ph', implode('/', $ph));
            $this->display();
        }
    }

    /* 退出登录 */
    public function logout()
    {
        if (is_login()) {
            D('Member')->logout();
            $this->success('退出成功！', U('User/login'));
        } else {
            $this->redirect('User/login');
        }
    }

    /* 验证码，用于登录和注册 */
    public function verify()
    {
        $verify = new \Think\Verify();
        $verify->entry(1);
    }

    /* 用户密码找回首页 */
    public function mi($username = '', $email = '', $verify = '')
    {
        $username = strval($username);
        $email = strval($email);

        if (IS_POST) { //登录验证
            //检测验证码

            if (!check_verify($verify)) {
                $this->error('验证码输入错误');
            }

            //根据用户名获取用户UID
            $user = D('User/UcenterMember')->where(array('username' => $username, 'email' => $email, 'status' => 1))->find();
            $uid = $user['id'];
            if (!$uid) {
                $this->error("用户名或邮箱错误");
            }

            //生成找回密码的验证码
            $verify = $this->getResetPasswordVerifyCode($uid);

            //发送验证邮箱
            $url = 'http://' . $_SERVER['HTTP_HOST'] . U('Home/User/reset?uid=' . $uid . '&verify=' . $verify);
            $content = C('USER_RESPASS') . "<br/>" . $url . "<br/>" . C('WEB_SITE') . "系统自动发送--请勿直接回复<br/>" . date('Y-m-d H:i:s', TIME()) . "</p>";
            send_mail($email, C('WEB_SITE') . "密码找回", $content);
            $this->success('密码找回邮件发送成功', U('User/login'));
        } else {
            if (is_login()) {
                redirect(U('Weibo/Index/index'));
            }

            $this->display();
        }
    }

    /**
     * 重置密码
     */
    public function reset($uid, $verify)
    {
        //检查参数
        $uid = intval($uid);
        $verify = strval($verify);
        if (!$uid || !$verify) {
            $this->error("参数错误");
        }

        //确认邮箱验证码正确
        $expectVerify = $this->getResetPasswordVerifyCode($uid);
        if ($expectVerify != $verify) {
            $this->error("参数错误");
        }

        //将邮箱验证码储存在SESSION
        session('reset_password_uid', $uid);
        session('reset_password_verify', $verify);

        //显示新密码页面
        $this->display();
    }

    public function doReset($password, $repassword)
    {
        //确认两次输入的密码正确
        if ($password != $repassword) {
            $this->error('两次输入的密码不一致');
        }

        //读取SESSION中的验证信息
        $uid = session('reset_password_uid');
        $verify = session('reset_password_verify');

        //确认验证信息正确
        $expectVerify = $this->getResetPasswordVerifyCode($uid);
        if ($expectVerify != $verify) {
            $this->error("验证信息无效");
        }

        //将新的密码写入数据库
        $data = array('id' => $uid, 'password' => $password);
        $model = D('User/UcenterMember');
        $data = $model->create($data);
        if (!$data) {
            $this->error('密码格式不正确');
        }
        $result = $model->where(array('id' => $uid))->save($data);
        if ($result === false) {
            $this->error('数据库写入错误');
        }

        //显示成功消息
        $this->success('密码重置成功', U('Home/User/login'));
    }

    private function getResetPasswordVerifyCode($uid)
    {
        $user = D('User/UcenterMember')->where(array('id' => $uid))->find();
        $clear = implode('|', array($user['uid'], $user['username'], $user['last_login_time'], $user['password']));
        $verify = thinkox_hash($clear, UC_AUTH_KEY);
        return $verify;
    }

    /**
     * 获取用户注册错误信息
     * @param  integer $code 错误编码
     * @return string        错误信息
     */
    public function showRegError($code = 0)
    {
        switch ($code) {
            case -1:
                $error = '用户名长度必须在4-16个字符以内！';
                break;
            case -2:
                $error = '用户名被禁止注册！';
                break;
            case -3:
                $error = '用户名被占用！';
                break;
            case -4:
                $error = '密码长度必须在6-30个字符之间！';
                break;
            case -5:
                $error = '邮箱格式不正确！';
                break;
            case -6:
                $error = '邮箱长度必须在4-32个字符之间！';
                break;
            case -7:
                $error = '邮箱被禁止注册！';
                break;
            case -8:
                $error = '邮箱被占用！';
                break;
            case -9:
                $error = '手机格式不正确！';
                break;
            case -10:
                $error = '手机被禁止注册！';
                break;
            case -11:
                $error = '手机号被占用！';
                break;
            case -20:
                $error = '用户名只能由数字、字母和"_"组成！';
                break;
            case -30:
                $error = '昵称被占用！';
                break;
            case -31:
                $error = '昵称被禁止注册！';
                break;
            case -32:
                $error = '昵称只能由数字、字母、汉字和"_"组成！';
                break;
            case -33:
                $error = '昵称不能少于两个字！';
                break;
            default:
                $error = '未知错误24';
        }
        return $error;
    }


    /**
     * 修改密码提交
     * @author huajie <banhuajie@163.com>
     */
    public function profile()
    {
        if (!is_login()) {
            $this->error('您还没有登陆', U('User/login'));
        }
        if (IS_POST) {
            //获取参数
            $uid = is_login();
            $password = I('post.old');
            $repassword = I('post.repassword');
            $data['password'] = I('post.password');
            empty($password) && $this->error('请输入原密码');
            empty($data['password']) && $this->error('请输入新密码');
            empty($repassword) && $this->error('请输入确认密码');

            if ($data['password'] !== $repassword) {
                $this->error('您输入的新密码与确认密码不一致');
            }

            $Api = new UserApi();
            $res = $Api->updateInfo($uid, $password, $data);
            if ($res['status']) {
                $this->success('修改密码成功！');
            } else {
                $this->error($res['info']);
            }
        } else {
            $this->display();
        }
    }

    /**
     * doSendVerify  发送验证码
     * @param $account
     * @param $verify
     * @param $type
     * @return bool|string
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function doSendVerify($account, $verify, $type)
    {
        switch ($type) {
            case 'mobile':
                //TODO 手机短信验证
                return true;
                break;
            case 'email':
                //发送验证邮箱
                //TODO 邮箱发送模版
                $content = modC('REG_EMAIL_VERIFY', '{$verify}', 'USERCONFIG');
                $content = str_replace('{$verify}', $verify, $content);
                $content = str_replace('{$account}', $account, $content);
                $res = send_mail($account, C('WEB_SITE') . '邮箱验证', $content);
                return $res;
                break;
        }

    }

    /**
     * activate  提示激活页面
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function activate()
    {

        // $aUid = I('get.uid',0,'intval');
        $aUid = session('temp_login_uid');
        $status = D('User/UcenterMember')->where(array('id' => $aUid))->getField('status');
        if ($status != 3) {
            redirect(U('ucenter/member/login'));
        }
        $info = query_user(array('uid', 'nickname', 'email'), $aUid);
        $this->assign($info);
        $this->display();
    }

    /**
     * reSend  重发邮件
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function reSend()
    {
        $res = $this->activateVerify();
        if ($res === true) {
            $this->success('发送成功', 'refresh');
        } else {
            $this->error('发送失败，请稍候再试！' . $res, 'refresh');
        }

    }

    /**
     * changeEmail  更改邮箱
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function changeEmail()
    {
        $aEmail = I('post.email', '', 'op_t');
        $aUid = session('temp_login_uid');
        $ucenterMemberModel = D('User/UcenterMember');
        $ucenterMemberModel->where(array('id' => $aUid))->getField('status');
        if ($ucenterMemberModel->where(array('id' => $aUid))->getField('status') != 3) {
            $this->error('权限不足！');
        }
        $ucenterMemberModel->where(array('id' => $aUid))->setField('email', $aEmail);
        clean_query_user_cache($aUid, 'email');
        $res = $this->activateVerify();
        $this->success('更换成功，请登录邮箱进行激活！如没收到激活信请稍候再试！', 'refresh');
    }

    /**
     * activateVerify 添加激活验证
     * @return bool|string
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    private function activateVerify()
    {
        $aUid = session('temp_login_uid');
        $email = D('User/UcenterMember')->where(array('id' => $aUid))->getField('email');
        $verify = D('Verify')->addVerify($email, 'email', $aUid);
        $res = $this->sendActivateEmail($email, $verify, $aUid); //发送激活邮件
        return $res;
    }

    /**
     * sendActivateEmail   发送激活邮件
     * @param $account
     * @param $verify
     * @return bool|string
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    private function sendActivateEmail($account, $verify, $uid)
    {

        $url = 'http://' . $_SERVER['HTTP_HOST'] . U('ucenter/member/doActivate?account=' . $account . '&verify=' . $verify . '&type=email&uid=' . $uid);
        $content = modC('REG_EMAIL_ACTIVATE', '{$url}', 'USERCONFIG');
        $content = str_replace('{$url}', $url, $content);
        $content = str_replace('{$title}', C('WEB_SITE'), $content);
        $res = send_mail($account, C('WEB_SITE') . '激活信', $content);


        return $res;
    }

    /**
     * saveAvatar  保存头像
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function saveAvatar()
    {

        $aCrop = I('post.crop', '', 'op_t');
        $aUid = session('temp_login_uid') ? session('temp_login_uid') : is_login();
        $aExt = I('post.ext', '', 'op_t');
        if (empty($aCrop)) {
            $this->success('保存成功！', session('temp_login_uid') ? U('Ucenter/member/step', array('step' => get_next_step('change_avatar'))) : 'refresh');
        }
        $dir = './Uploads/Avatar/' . $aUid;
        $dh = opendir($dir);
        while ($file = readdir($dh)) {
            if ($file != "." && $file != ".." && $file != 'original.' . $aExt) {
                $fullpath = $dir . "/" . $file;
                if (!is_dir($fullpath)) {
                    unlink($fullpath);
                } else {
                    deldir($fullpath);
                }
            }
        }
        closedir($dh);
        A('Ucenter/UploadAvatar', 'Widget')->cropPicture($aUid, $aCrop, $aExt);
        $res = M('avatar')->where(array('uid' => $aUid))->save(array('uid' => $aUid, 'status' => 1, 'is_temp' => 0, 'path' => "/" . $aUid . "/crop." . $aExt, 'create_time' => time()));
        if (!$res) {
            M('avatar')->add(array('uid' => $aUid, 'status' => 1, 'is_temp' => 0, 'path' => "/" . $aUid . "/crop." . $aExt, 'create_time' => time()));
        }
        clean_query_user_cache($aUid, array('avatar256', 'avatar128', 'avatar64'));
        $this->success('头像更新成功！', session('temp_login_uid') ? U('Ucenter/member/step', array('step' => get_next_step('change_avatar'))) : 'refresh');

    }

    /**
     * doActivate  激活步骤
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function doActivate()
    {
        $aAccount = I('get.account', '', 'op_t');
        $aVerify = I('get.verify', '', 'op_t');
        $aType = I('get.type', '', 'op_t');
        $aUid = I('get.uid', 0, 'intval');
        $check = D('Verify')->checkVerify($aAccount, $aType, $aVerify, $aUid);
        if ($check) {
            set_user_status($aUid, 1);
            $this->success('激活成功', U('Ucenter/member/step', array('step' => get_next_step('start'))));
        } else {
            $this->error('激活失败！');
        }


    }

    /**
     * checkAccount  ajax验证用户帐号是否符合要求
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function checkAccount()
    {
        $aAccount =  I('post.account', '', 'op_t');
        $aType = I('post.type', '', 'op_t');
        if(empty($aAccount)){
            $this->error('不能为空！');
        }
        check_username($aAccount, $email, $mobile, $aUnType);
        $mUcenter = D('User/UcenterMember');
        switch ($aType) {
            case 'username':
                empty($aAccount) && $this->error('用户名格式不正确！');
                $id = $mUcenter->where(array('username' => $aAccount))->getField('id');
                if ($id) {
                    $this->error('该用户名已经存在！');
                }
                preg_match("/^[a-zA-Z0-9_]{1,30}$/", $aAccount, $result);
                if (!$result) {
                    $this->error('只允许字母和数字和下划线！');
                }
                break;
            case 'email':
                empty($email) && $this->error('邮箱格式不正确！');
                $id = $mUcenter->where(array('email' => $email))->getField('id');
                if ($id) {
                    $this->error('该邮箱已经存在！');
                }
                break;
            case 'mobile':
                empty($mobile) && $this->error('手机格式不正确！');
                $id = $mUcenter->where(array('mobile' => $mobile))->getField('id');
                if ($id) {
                    $this->error('该手机号已经存在！');
                }
                break;
        }
        $this->success('验证成功');
    }

    /**
     * checkNickname  ajax验证昵称是否符合要求
     * @author:xjw129xjt(肖骏涛) xjt@ourstu.com
     */
    public function checkNickname()
    {
        $aNickname =  I('post.nickname', '', 'op_t');

        if(empty($aNickname)){
            $this->error('不能为空！');
        }
        $memberModel = D('member');
        $uid = $memberModel->where(array('nickname' => $aNickname))->getField('uid');
        if ($uid) {
            $this->error('该昵称已经存在！');
        }
        preg_match('/^(?!_|\s\')[A-Za-z0-9_\x80-\xff\s\']+$/', $aNickname, $result);
        if (!$result) {
            $this->error('只允许中文、字母和数字和下划线！');
        }

        $this->success('验证成功');
    }

}