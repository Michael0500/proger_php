CREATE OR REPLACE VIEW customer_tasks_view_v2 AS
WITH customer_aggregates AS (
SELECT
customer_rk,

        -- orgs
        string_agg(DISTINCT owned_by_internal_org_rk::text, ', ' ORDER BY owned_by_internal_org_rk) AS owned_by_internal_org_rk,

        -- debit block
        string_agg(DISTINCT acc_is_debit_blocked_flg::text, ', ' ORDER BY acc_is_debit_blocked_flg) AS acc_is_debit_blocked_flg,

        -- irb_account_block_amt_flg: 'Y' если есть хотя бы одна НЕ-NULL запись (включая 0)
        CASE
            WHEN bool_or(irb_account_block_amt IS NOT NULL) THEN 'Y'
            ELSE 'N'
        END AS irb_account_block_amt_flg

        -- ⚠️ Если поле текстовое и нужно игнорировать '' и ' ':
        -- WHEN bool_or(NULLIF(TRIM(irb_account_block_amt::text), '') IS NOT NULL) THEN 'Y'

    FROM dwh_accounts
    GROUP BY customer_rk
),
customer_data AS (
SELECT
d.customer_rk,
d.fcc_customer_id,
d.act_to_close_408,
d.customer_last_name_rus,
d.customer_first_name_rus,
d.customer_middle_name_rus,
d.reg_address_full_txt,
d.loc_address_full_txt,
d.loa_flg,
d.box_flg,
d.card_from_other_client_flg,
d.other_has_from_client_flg,
d.account_officer_nm,
d.max_open_dt_408,
d.max_last_oper_408,
d.dko_flg,
d.dbo_enter_flg,
d.irb_kyc_status_cd,
d.death_flg,
d.death_dt,
ca.owned_by_internal_org_rk,
ca.acc_is_debit_blocked_flg,
ca.irb_account_block_amt_flg  -- ← новое поле
FROM dwh_accounts d
LEFT JOIN customer_aggregates ca ON ca.customer_rk = d.customer_rk
)
SELECT
cd.fcc_customer_id,
cd.act_to_close_408,
t.notif_channel,
CONCAT_WS(' ', cd.customer_last_name_rus, cd.customer_first_name_rus, cd.customer_middle_name_rus) AS customer_fio,
cd.reg_address_full_txt,
cd.loc_address_full_txt,
t.plan_send,
t.status,
t.fcc_data_send,
cd.fcc_customer_id,
cd.owned_by_internal_org_rk,
cd.loa_flg,
cd.box_flg,
cd.card_from_other_client_flg,
cd.other_has_from_client_flg,
cd.account_officer_nm,
cd.irb_account_block_amt_flg AS irb_account_block_amt,  -- ← заменяем статичное 'N' на вычисленное
cd.acc_is_debit_blocked_flg,
cd.max_open_dt_408,
cd.max_last_oper_408,
cd.dko_flg,
cd.dbo_enter_flg,
cd.irb_kyc_status_cd,
cd.death_flg,
cd.death_dt
FROM customer_data cd
LEFT JOIN task_items t ON t.customer_num = cd.customer_rk;