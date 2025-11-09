<?php
if (!defined('ABSPATH')) exit;

function pm_leads_pricing_page() {

    if (isset($_POST['pm_leads_prices_nonce']) && wp_verify_nonce($_POST['pm_leads_prices_nonce'], 'pm_leads_prices')) {

        $prices = [
            'price_1'  => floatval($_POST['pm_price_1'] ?? 0),
            'price_5'  => floatval($_POST['pm_price_5'] ?? 0),
            'price_10' => floatval($_POST['pm_price_10'] ?? 0),
        ];

        update_option('pm_leads_credit_prices', $prices);
        echo '<div class="updated"><p>Prices updated.</p></div>';
    }

    $prices = get_option('pm_leads_credit_prices', [
        'price_1'  => 2,
        'price_5'  => 8,
        'price_10' => 15,
    ]);
?>
<div class="wrap">
    <h1>Credit Pricing</h1>

    <form method="post">
        <?php wp_nonce_field('pm_leads_prices', 'pm_leads_prices_nonce'); ?>

        <table class="form-table">
            <tr>
                <th>1 Credit (£)</th>
                <td><input type="number" step="0.01" name="pm_price_1" value="<?php echo esc_attr($prices['price_1']); ?>"></td>
            </tr>
            <tr>
                <th>5 Credits (£)</th>
                <td><input type="number" step="0.01" name="pm_price_5" value="<?php echo esc_attr($prices['price_5']); ?>"></td>
            </tr>
            <tr>
                <th>10 Credits (£)</th>
                <td><input type="number" step="0.01" name="pm_price_10" value="<?php echo esc_attr($prices['price_10']); ?>"></td>
            </tr>
        </table>

        <p><button class="button button-primary">Save</button></p>
    </form>
</div>
<?php
}
