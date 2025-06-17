<?php
/*
Plugin Name: Mpay支付发卡系统（支付宝+微信）
Description: 使用Mpay聚合支付（支持支付宝+微信），支付成功后自动发放卡密。使用短代码 [mpay_unlock price="0.01" name="测试内容"]任意提示文字[/mpay_unlock]。注意：不要启用缓存功能，否则会影响卡密分发逻辑！
Version: 2.0
Author: 码铃薯
*/

// 添加后台设置页面
add_action('admin_menu', function() {
    add_options_page('Mpay 设置', 'Mpay 支付设置', 'manage_options', 'mpay-settings', 'mpay_settings_page');
});

function mpay_settings_page() {
    ?>
    <div class="wrap">
        <h2>Mpay 支付接口设置</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('mpay_settings_group');
            do_settings_sections('mpay-settings');
            submit_button();
            ?>
        </form>
        <hr>
        <h2>卡密池管理</h2>
        <form method="post">
            <textarea name="mpay_card_pool_input" rows="10" cols="70" placeholder="每行一个卡密"><?php echo implode("\n", get_option('mpay_card_pool_global', [])); ?></textarea><br>
            <input type="submit" name="mpay_save_cards" class="button-primary" value="保存卡密池">
        </form>
        <p>当前剩余卡密数量：<?php echo count(array_diff(get_option('mpay_card_pool_global', []), get_option('mpay_card_used_global', []))); ?></p>
    </div>
	<span style="color:#888;font-size:13px;">📌 短代码使用示范[mpay_unlock price="0.88" name="激活卡密"]点击这里购买卡密[/mpay_unlock]
</span>
    <?php
}

add_action('admin_init', function() {
    register_setting('mpay_settings_group', 'mpay_pid');
    register_setting('mpay_settings_group', 'mpay_key');

    add_settings_section('mpay_main_section', '基本设置', null, 'mpay-settings');

    add_settings_field('mpay_pid', '商户ID（PID）', function() {
        $value = get_option('mpay_pid', '');
        echo "<input type='text' name='mpay_pid' value='" . esc_attr($value) . "' class='regular-text'>";
    }, 'mpay-settings', 'mpay_main_section');

    add_settings_field('mpay_key', '商户密钥（KEY）', function() {
        $value = get_option('mpay_key', '');
        echo "<input type='text' name='mpay_key' value='" . esc_attr($value) . "' class='regular-text'>";
    }, 'mpay-settings', 'mpay_main_section');
});

// 保存卡密池
add_action('admin_init', function() {
    if (isset($_POST['mpay_save_cards'])) {
        $lines = explode("\n", trim($_POST['mpay_card_pool_input']));
        $cards = array_filter(array_map('trim', $lines));
        update_option('mpay_card_pool_global', $cards);
    }
});

// 注册短代码
add_shortcode('mpay_unlock', function($atts, $content = null) {
    global $post;
    $post_id = $post->ID;
    $atts = shortcode_atts([
        'price' => '1.00',
        'name' => get_the_title($post_id),
    ], $atts);

    $order_id = sanitize_text_field($_GET['order_id'] ?? '');
    $used_cards = get_option('mpay_card_used_global', []);
    $card = $order_id && isset($used_cards[$order_id]) ? $used_cards[$order_id] : '';

    ob_start();
    ?>
    <div id="mpay-container-<?php echo $post_id; ?>">
		<?php if (!empty($card)): ?>
            <div style="padding:15px;border:1px solid #ccc;background:#f9fff9;">
            ✅ 您的卡密是：<strong><?php echo esc_html($card); ?></strong><br>
            <span style="color:#888;font-size:13px;">📌 温馨提示：<strong>卡密仅保存24小时</strong>，请尽快使用，<strong>过期将无法找回</strong>！</span>
            </div>
        <?php else: ?>
            <?php
            $pid = get_option('mpay_pid', '');
            $key = get_option('mpay_key', '');
            $order_id = 'order_' . time() . rand(1000, 9999);
            $notify_url = site_url('/mpay-notify');
            $return_url = site_url('/mpay-return?order_id=' . $order_id . '&post_id=' . $post_id);

            function generate_pay_url($type, $pid, $key, $order_id, $notify_url, $return_url, $name, $price) {
                $data = [
                    'pid' => $pid,
                    'type' => $type,
                    'out_trade_no' => $order_id . "_$type",
                    'notify_url' => $notify_url,
                    'return_url' => $return_url,
                    'name' => $name,
                    'money' => $price,
                    'sign_type' => 'MD5'
                ];
                ksort($data);
                $sign_str = '';
                foreach ($data as $k => $v) {
                    if ($k !== 'sign' && $k !== 'sign_type' && $v !== '') {
                        $sign_str .= "$k=$v&";
                    }
                }
                $sign_str = rtrim($sign_str, '&') . $key;
                $data['sign'] = md5($sign_str);

                return 'https://mpay.52yzk.com/submit.php?' . http_build_query($data);
            }

            $alipay_url = generate_pay_url('alipay', $pid, $key, $order_id, $notify_url, $return_url, $atts['name'], $atts['price']);
            $wxpay_url = generate_pay_url('wxpay', $pid, $key, $order_id, $notify_url, $return_url, $atts['name'], $atts['price']);
            ?>

            <div style="border:1px solid #ccc;padding:15px;margin:15px 0;background:#fff9f9;text-align:left;">
                <p><strong>此内容需支付 <?php echo esc_html($atts['price']); ?> 元获取卡密</strong></p>
                <a href="<?php echo esc_url($alipay_url); ?>" target="_blank"><img src="/wp-content/plugins/mpay-content-unlocker/img/alipay.jpg" width="160"></a>
                <a href="<?php echo esc_url($wxpay_url); ?>" target="_blank"><img src="/wp-content/plugins/mpay-content-unlocker/img/wxpay.jpg" width="160"></a>
                <p style="font-size:13px;color:#888;margin-top:10px;">支付完成后页面将自动跳转并发放卡密</p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// 支付完成返回逻辑，发放卡密并绑定订单ID
add_action('init', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/mpay-return') !== false && isset($_GET['order_id'])) {
        $order_id = sanitize_text_field($_GET['order_id']);
        $post_id = intval($_GET['post_id'] ?? 0);

        $used_cards = get_option('mpay_card_used_global', []);
        if (isset($used_cards[$order_id])) {
            // 已发放，直接跳转
            wp_redirect(get_permalink($post_id) . '?order_id=' . urlencode($order_id));
            exit;
        }

        $all_cards = get_option('mpay_card_pool_global', []);
        $used_values = array_values($used_cards);
        $available = array_diff($all_cards, $used_values);

        if (!empty($available)) {
            $card = array_shift($available);
            $used_cards[$order_id] = $card;
            update_option('mpay_card_used_global', $used_cards);

            wp_redirect(get_permalink($post_id) . '?order_id=' . urlencode($order_id));
            exit;
        } else {
            echo '❌ 暂无可用卡密，请联系管理员补货';
            exit;
        }
    }
});

// 支付通知接口（不再发卡，仅验签）
add_action('init', function () {
    if (strpos($_SERVER['REQUEST_URI'], '/mpay-notify') !== false) {
        $key = get_option('mpay_key', '');
        $data = $_GET;
        $sign = $data['sign'] ?? '';
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        $sign_str = http_build_query($data) . $key;
        $verify_sign = md5($sign_str);

        if ($verify_sign === $sign && ($data['trade_status'] ?? '') === 'TRADE_SUCCESS') {
            echo 'success';
        } else {
            echo 'fail';
        }
        exit;
    }
});
?>