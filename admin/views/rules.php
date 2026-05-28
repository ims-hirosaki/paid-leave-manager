<?php if ( ! defined('ABSPATH') ) exit;

$settings = PL_Rules::get_settings();
$matrix   = PL_Rules::get_rules_matrix();
$tenures  = array(
    6  => '6ヶ月',   18 => '1年6ヶ月', 30 => '2年6ヶ月', 42 => '3年6ヶ月',
    54 => '4年6ヶ月', 66 => '5年6ヶ月', 78 => '6年6ヶ月以上',
);
$weeks = array( 1=>'週1', 2=>'週2', 3=>'週3', 4=>'週4', 5=>'週5', 6=>'週6' );
$dows  = array( 0=>'日', 1=>'月', 2=>'火', 3=>'水', 4=>'木', 5=>'金', 6=>'土' );
$legal_dows = json_decode( $settings['legal_holiday_dow'] ?? '[0]', true ) ?: array(0);
?>
<div class="wrap pl-wrap" id="pl-rules-page">
<h1 class="pl-page-title"><span class="dashicons dashicons-admin-settings"></span> 有給ルール設定</h1>

<div class="pl-card">
    <div class="pl-card-title">付与日数ルール <span class="pl-badge">勤続年数 × 週勤務日数</span></div>
    <p class="pl-hint">各セルに付与日数を入力してください。労働基準法の標準値がデフォルトで入っています。</p>
    <div class="pl-table-wrap">
    <table class="pl-rules-table">
        <thead>
            <tr>
                <th>勤続期間</th>
                <?php foreach ( $weeks as $w => $label ) : ?>
                    <th><?php echo esc_html($label); ?>勤務</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $tenures as $tenure => $tenure_label ) : ?>
            <tr>
                <td class="pl-tenure-label"><?php echo esc_html($tenure_label); ?></td>
                <?php foreach ( $weeks as $w => $wlabel ) :
                    $val = $matrix[$tenure][$w] ?? 0;
                ?>
                    <td>
                        <input type="number"
                            class="pl-rule-input"
                            name="rules[<?php echo $tenure; ?>][<?php echo $w; ?>]"
                            value="<?php echo esc_attr($val); ?>"
                            min="0" max="40" step="0.5">
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="pl-field-row" style="margin-top:1rem;">
        <label class="pl-label">ルール適用開始日</label>
        <input type="date" id="plEffectiveDate"
            value="<?php echo esc_attr( $settings['rules_effective_date'] ?? date('Y-m-d') ); ?>"
            class="pl-input" style="width:180px;">
    </div>
</div>

<div class="pl-card">
    <div class="pl-card-title">有給休暇の有効期限・繰り越しルール</div>
    <div class="pl-settings-grid">
        <div class="pl-field">
            <label class="pl-label">付与から何年間繰り越し可能か</label>
            <div class="pl-input-unit">
                <input type="number" id="plCarryoverYears" class="pl-input"
                    value="<?php echo esc_attr($settings['carryover_years'] ?? 2); ?>"
                    min="1" max="5" step="1" style="width:80px;">
                <span class="pl-unit">年間</span>
            </div>
        </div>
        <div class="pl-field">
            <label class="pl-label">付与から何年間で消滅するか</label>
            <div class="pl-input-unit">
                <input type="number" id="plExpirationYears" class="pl-input"
                    value="<?php echo esc_attr($settings['expiration_years'] ?? 2); ?>"
                    min="1" max="5" step="1" style="width:80px;">
                <span class="pl-unit">年間</span>
            </div>
        </div>
        <div class="pl-field">
            <label class="pl-label">年間最低取得義務日数</label>
            <div class="pl-input-unit">
                <input type="number" id="plMinAnnualDays" class="pl-input"
                    value="<?php echo esc_attr($settings['min_annual_days'] ?? 5); ?>"
                    min="0" max="20" step="1" style="width:80px;">
                <span class="pl-unit">日</span>
            </div>
        </div>
    </div>
</div>

<div class="pl-card">
    <div class="pl-card-title">消化単位設定</div>
    <p class="pl-hint">現在有効な消化単位を設定します。将来の半日・時間単位追加に備えて管理します。</p>
    <div class="pl-checkboxes">
        <?php
        $current_units = json_decode( $settings['consumption_units'] ?? '["1.0"]', true ) ?: array('1.0');
        $unit_options  = array( '1.0' => '1日単位', '0.5' => '半日単位（0.5日）' );
        foreach ( $unit_options as $unit_val => $unit_label ) : ?>
            <label class="pl-checkbox-label">
                <input type="checkbox" class="pl-unit-checkbox" value="<?php echo esc_attr($unit_val); ?>"
                    <?php checked( in_array($unit_val, $current_units, true) ); ?>>
                <?php echo esc_html($unit_label); ?>
            </label>
        <?php endforeach; ?>
    </div>
</div>

<div class="pl-card">
    <div class="pl-card-title">法定休日・祝日設定</div>
    <div class="pl-settings-grid">
        <div class="pl-field">
            <label class="pl-label">法定休日（曜日）</label>
            <div class="pl-checkboxes">
                <?php foreach ( $dows as $d => $dlabel ) : ?>
                    <label class="pl-checkbox-label">
                        <input type="checkbox" class="pl-dow-checkbox" value="<?php echo $d; ?>"
                            <?php checked( in_array($d, $legal_dows, true) ); ?>>
                        <?php echo esc_html($dlabel); ?>曜日
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="pl-field">
            <label class="pl-label">国民の祝日を法定休日として扱う</label>
            <label class="pl-toggle">
                <input type="checkbox" id="plUseNationalHolidays"
                    <?php checked( ($settings['use_national_holidays'] ?? '1') === '1' ); ?>>
                <span class="pl-toggle-slider"></span>
            </label>
        </div>
        <div class="pl-field">
            <label class="pl-label">祝日データ</label>
            <button type="button" id="plFetchHolidays" class="pl-btn pl-btn-secondary">
                <span class="dashicons dashicons-update"></span> 内閣府CSVから祝日を取得・更新
            </button>
            <p class="pl-hint" style="margin-top:.5rem;">毎年4月1日に自動更新されます。手動で今すぐ更新する場合はこちら。</p>
        </div>
    </div>
</div>

<div class="pl-form-actions">
    <button type="button" id="plSaveRules" class="pl-btn pl-btn-primary">
        <span class="dashicons dashicons-yes"></span> ルールを保存する
    </button>
</div>
</div>
