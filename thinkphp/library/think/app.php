<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

/**
 * App 应用管理
 * @author  liu21st <liu21st@gmail.com>
 */
class App
{

    /**
     * 执行应用程序
     * @access public
     * @return void
     */
    public static function run()
    {
        // 初始化应用（公共模块）
        self::initModule(COMMON_MODULE, Config::get());

        // 读取扩展配置文件
        if (Config::get('extra_config_list')) {
            foreach (Config::get('extra_config_list') as $file) {
                Config::load($file, $file);
            }
        }

        // 获取配置参数
        $config = Config::get();

        // 加载额外文件
        if (!empty($config['extra_file_list'])) {
            foreach ($config['extra_file_list'] as $file) {
                $file = strpos($file, '.') ? $file : APP_PATH . $file . EXT;
                if (is_file($file)) {
                    include_once $file;
                }
            }
        }

        // 日志初始化
        Log::init($config['log']);
        // 缓存初始化
        Cache::connect($config['cache']);

        // 设置系统时区
        date_default_timezone_set($config['default_timezone']);

        // 监听app_init
        APP_HOOK && Hook::listen('app_init');

        // 开启多语言机制
        if ($config['lang_switch_on']) {
            // 获取当前语言
            defined('LANG_SET') or define('LANG_SET', Lang::range());
            // 加载系统语言包
            Lang::load(THINK_PATH . 'lang' . DS . LANG_SET . EXT);
        }

        // 启动session CLI 不开启
        if (!IS_CLI && $config['use_session']) {
            Session::init($config['session']);
        }

        // URL路由检测
        $dispatch = self::route($config);

        // 监听app_begin
        APP_HOOK && Hook::listen('app_begin', $dispatch);

        // 根据类型调度
        switch ($dispatch['type']) {
            case 'redirect':
                // 执行重定向跳转
                header('Location: ' . $dispatch['url'], true, $dispatch['status']);
                break;
            case 'module':
                // 模块/控制器/操作
                $data = self::module($dispatch['data'], $config);
                break;
            case 'action':
                // 执行操作
                $data = Loader::action($dispatch['action'], $dispatch['params']);
                break;
            case 'behavior':
                // 执行行为
                $data = Hook::exec($dispatch['class'], $dispatch['method'], $dispatch['params']);
                break;
            case 'regex_closure':
                // 正则闭包
                $data = self::invokeRegex($dispatch['closure'], $dispatch['params']);
                break;
            case 'rule_closure':
                // 规则闭包
                $data = self::invokeRule($dispatch['closure'], $dispatch['params']);
                break;
            default:
                throw new Exception('dispatch type not support', 10008);
        }
        // 监听app_end
        APP_HOOK && Hook::listen('app_end', $data);
        // 输出数据到客户端
        return Response::send($data, Response::type(), Config::get('response_return'));
    }

    // 执行正则匹配下的闭包方法 支持参数调用
    private static function invokeRegex($closure, $var = [])
    {
        $reflect = new \ReflectionFunction($closure);
        $params  = $reflect->getParameters();
        $args    = [];
        array_shift($var);
        foreach ($params as $param) {
            $name = $param->getName();
            if (!empty($var)) {
                $args[] = array_shift($var);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
        }
        return $reflect->invokeArgs($args);
    }

    // 执行规则匹配下的闭包方法 支持参数调用
    private static function invokeRule($closure, $var = [])
    {
        $reflect = new \ReflectionFunction($closure);
        $params  = $reflect->getParameters();
        $args    = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if (isset($var[$name])) {
                $args[] = $var[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
        }
        return $reflect->invokeArgs($args);
    }

    // 执行 模块/控制器/操作
    private static function module($result, $config)
    {
        if (APP_MULTI_MODULE) {
            // 多模块部署
            $module = strtolower($result[0] ?: $config['default_module']);
            if ($maps = $config['url_module_map']) {
                if (isset($maps[$module])) {
                    // 记录当前别名
                    define('MODULE_ALIAS', $module);
                    // 获取实际的项目名
                    $module = $maps[MODULE_ALIAS];
                } elseif (array_search($module, $maps)) {
                    // 禁止访问原始项目
                    $module = '';
                }
            }
            // 获取模块名称
            define('MODULE_NAME', defined('BIND_MODULE') ? BIND_MODULE : strip_tags($module));

            // 模块初始化
            if (MODULE_NAME && !in_array(MODULE_NAME, $config['deny_module_list']) && is_dir(APP_PATH . MODULE_NAME)) {
                APP_HOOK && Hook::listen('app_begin');
                define('MODULE_PATH', APP_PATH . MODULE_NAME . DS);
                define('VIEW_PATH', MODULE_PATH . VIEW_LAYER . DS);

                // 初始化模块
                self::initModule(MODULE_NAME, $config);
            } else {
                throw new Exception('module [ ' . MODULE_NAME . ' ] not exists ', 10005);
            }
        } else {
            // 单一模块部署
            define('MODULE_NAME', '');
            define('MODULE_PATH', APP_PATH);
            define('VIEW_PATH', MODULE_PATH . VIEW_LAYER . DS);
        }

        // 获取控制器名
        $controller = strip_tags(strtolower($result[1] ?: Config::get('default_controller')));
        define('CONTROLLER_NAME', defined('BIND_CONTROLLER') ? BIND_CONTROLLER : $controller);

        // 获取操作名
        $action = strip_tags(strtolower($result[2] ?: Config::get('default_action')));
        define('ACTION_NAME', defined('BIND_ACTION') ? BIND_ACTION : $action);

        // 执行操作
        if (!preg_match('/^[A-Za-z](\/|\.|\w)*$/', CONTROLLER_NAME)) {
            // 安全检测
            throw new Exception('illegal controller name:' . CONTROLLER_NAME, 10000);
        }
        if (Config::get('action_bind_class')) {
            $class    = self::bindActionClass(Config::get('empty_controller'));
            $instance = new $class;
            // 操作绑定到类后 固定执行run入口
            $action = 'run';
        } else {
            $instance = Loader::controller(CONTROLLER_NAME, '', Config::get('empty_controller'));
            // 获取当前操作名
            $action = ACTION_NAME . Config::get('action_suffix');
        }

        if (!$instance) {
            throw new Exception('class [ ' . Loader::parseClass(MODULE_NAME, CONTROLLER_LAYER, CONTROLLER_NAME) . ' ] not exists', 10001);
        }
        try {
            // 操作方法开始监听
            $call = [$instance, $action];
            APP_HOOK && Hook::listen('action_begin', $call);
            if (!preg_match('/^[A-Za-z](\w)*$/', $action)) {
                // 非法操作
                throw new \ReflectionException();
            }
            //执行当前操作
            $method = new \ReflectionMethod($instance, $action);
            if ($method->isPublic()) {
                // URL参数绑定检测
                if (Config::get('url_params_bind') && $method->getNumberOfParameters() > 0) {
                    // 获取绑定参数
                    $args = self::getBindParams($method, Config::get('url_parmas_bind_type'));
                    // 全局过滤
                    array_walk_recursive($args, 'think\\Input::filterExp');
                    $data = $method->invokeArgs($instance, $args);
                } else {
                    $data = $method->invoke($instance);
                }
            } else {
                // 操作方法不是Public 抛出异常
                throw new \ReflectionException();
            }
        } catch (\ReflectionException $e) {
            // 操作不存在
            if (method_exists($instance, '_empty')) {
                $method = new \ReflectionMethod($instance, '_empty');
                $data   = $method->invokeArgs($instance, [$action, '']);
            } else {
                throw new Exception('method [ ' . (new \ReflectionClass($instance))->getName() . '->' . $action . ' ] not exists ', 10002);
            }
        }
        return $data;
    }

    // 操作绑定到类：模块\controller\控制器\操作类
    private static function bindActionClass($emptyController)
    {
        if (is_dir(MODULE_PATH . CONTROLLER_LAYER . DS . str_replace('.', DS, CONTROLLER_NAME))) {
            $namespace = MODULE_NAME . '\\' . CONTROLLER_LAYER . '\\' . str_replace('.', '\\', CONTROLLER_NAME) . '\\';
        } else {
            // 空控制器
            $namespace = MODULE_NAME . '\\' . CONTROLLER_LAYER . '\\' . $emptyController . '\\';
        }
        $actionName = strtolower(ACTION_NAME);
        if (class_exists($namespace . $actionName)) {
            $class = $namespace . $actionName;
        } elseif (class_exists($namespace . '_empty')) {
            // 空操作
            $class = $namespace . '_empty';
        } else {
            throw new Exception('bind action class not exists :' . ACTION_NAME, 10003);
        }
        return $class;
    }

    // 获取操作方法的参数绑定
    private static function getBindParams($method, $paramsBindType)
    {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                $vars = array_merge($_GET, $_POST);
                break;
            case 'PUT':
                parse_str(file_get_contents('php://input'), $vars);
                break;
            default:
                $vars = $_GET;
        }
        $params = $method->getParameters();
        foreach ($params as $param) {
            $name = $param->getName();
            if (1 == $paramsBindType && !empty($vars)) {
                $args[] = array_shift($vars);
            } elseif (0 == $paramsBindType && isset($vars[$name])) {
                $args[] = $vars[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new Exception('url param bind error:' . $name, 10004);
            }
        }
        return $args;
    }

    // 初始化模块
    private static function initModule($module, $config)
    {
        // 定位模块目录
        $module = (COMMON_MODULE == $module || !APP_MULTI_MODULE) ? '' : $module . DS;

        // 加载初始化文件
        if (is_file(APP_PATH . $module . 'init' . EXT)) {
            include APP_PATH . $module . 'init' . EXT;
        } else {
            $path = APP_PATH . $module;
            // 加载模块配置
            Config::load($module . 'config');

            // 加载应用状态配置
            if ($config['app_status']) {
                Config::load($module . $config['app_status']);
            }

            // 加载别名文件
            if (is_file($path . 'alias' . EXT)) {
                Loader::addMap(include $path . 'alias' . EXT);
            }

            // 加载行为扩展文件
            if (APP_HOOK && is_file($path . 'tags' . EXT)) {
                Hook::import(include $path . 'tags' . EXT);
            }

            // 加载公共文件
            if (is_file($path . 'common' . EXT)) {
                include $path . 'common' . EXT;
            }

            // 加载当前模块语言包
            if ($config['lang_switch_on'] && $module) {
                Lang::load($path . 'lang' . DS . LANG_SET . EXT);
            }
        }
    }

    // 分析 PATH_INFO
    private static function parsePathinfo($config)
    {
        if (isset($_GET[$config['var_pathinfo']])) {
            // 判断URL里面是否有兼容模式参数
            $_SERVER['PATH_INFO'] = $_GET[$config['var_pathinfo']];
            unset($_GET[$config['var_pathinfo']]);
        } elseif (IS_CLI) {
            // CLI模式下 index.php module/controller/action/params/...
            $_SERVER['PATH_INFO'] = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
        }

        // 监听path_info
        APP_HOOK && Hook::listen('path_info');
        // 分析PATHINFO信息
        if (!isset($_SERVER['PATH_INFO'])) {
            foreach ($config['pathinfo_fetch'] as $type) {
                if (!empty($_SERVER[$type])) {
                    $_SERVER['PATH_INFO'] = (0 === strpos($_SERVER[$type], $_SERVER['SCRIPT_NAME'])) ?
                    substr($_SERVER[$type], strlen($_SERVER['SCRIPT_NAME'])) : $_SERVER[$type];
                    break;
                }
            }
        }
    }

    /**
     * URL路由检测（根据PATH_INFO)
     * @access public
     * @param $config
     * @throws Exception
     */
    public static function route($config)
    {
        // 解析PATH_INFO
        self::parsePathinfo($config);

        // 检测域名部署
        if (!IS_CLI && !empty($config['url_domain_deploy'])) {
            if ($config['url_domain_rules']) {
                // 注册域名路由规则
                Route::domain($config['url_domain_rules']);
            }
            // 检测域名路由
            if ($match = Route::checkDomain()) {
                (!defined('BIND_MODULE') && !empty($match[0])) && define('BIND_MODULE', $match[0]);
                (!defined('BIND_CONTROLLER') && !empty($match[1])) && define('BIND_CONTROLLER', $match[1]);
                (!defined('BIND_ACTION') && !empty($match[2])) && define('BIND_ACTION', $match[2]);
            }
        }

        if (empty($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '';
            define('__INFO__', '');
            define('__EXT__', '');
            $result = ['type' => 'module', 'data' => [null, null, null]];
        } else {
            $_SERVER['PATH_INFO'] = trim($_SERVER['PATH_INFO'], '/');
            define('__INFO__', $_SERVER['PATH_INFO']);
            // URL后缀
            define('__EXT__', strtolower(pathinfo($_SERVER['PATH_INFO'], PATHINFO_EXTENSION)));
            // 检测URL禁用后缀
            if ($config['url_deny_suffix'] && preg_match('/\.(' . $config['url_deny_suffix'] . ')$/i', __INFO__)) {
                throw new Exception('url suffix deny');
            }
            // 去除正常的URL后缀
            $_SERVER['PATH_INFO'] = preg_replace($config['url_html_suffix'] ? '/\.(' . trim($config['url_html_suffix'], '.') . ')$/i' : '/\.' . __EXT__ . '$/i', '', __INFO__);
            $depr                 = $config['pathinfo_depr'];
            // 还原劫持后真实pathinfo
            $path_info =
                (defined('BIND_MODULE') ? BIND_MODULE . $depr : '') .
                (defined('BIND_CONTROLLER') ? BIND_CONTROLLER . $depr : '') .
                (defined('BIND_ACTION') ? BIND_ACTION . $depr : '') .
                $_SERVER['PATH_INFO'];

            // 路由检测
            if (!empty($config['url_route_on'])) {
                // 开启路由 注册路由定义文件
                Route::register(!empty($config['route']) ? $config['route'] : null);
                // 路由检测（根据路由定义返回不同的URL调度）
                $result = Route::check($path_info, $depr);
                if (false === $result) {
                    // 路由无效
                    if ($config['url_route_must']) {
                        throw new Exception('route not define ');
                    } else {
                        // 继续分析为模块/控制器/操作/参数...方式URL
                        $result = Route::parseUrl($path_info, $depr);
                    }
                }
            } else {
                // 分析URL地址 采用 模块/控制器/操作/参数...
                $result = Route::parseUrl($path_info, $depr);
            }
        }
        return $result;
    }

}
