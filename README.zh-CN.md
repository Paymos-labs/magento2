# Paymos Magento 2 支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 Magento Open Source 与 Adobe Commerce 稳定币支付模块。它在结账页新增一种 USDT/USDC 收款方式：买家下单后跳转至 Paymos 托管收银台，自行选择代币和网络并从个人钱包发起转账。当经过签名验证的 webhook 回报链上付款已确认时，Magento 自动为订单开票入账——而非依赖买家返回独立站页面。

[![观看视频：12 款官方 CMS 稳定币支付插件 — Paymos 一键连接](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，内置 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- Magento Open Source 或 Adobe Commerce 2.4 —— 模块要求 `magento/framework` 103.0 及以上版本
- PHP 8.3、8.4 或 8.5（Adobe Commerce 2.4.9 已移除对 8.1/8.2 的支持）
- 具备执行 `bin/magento` 的 Shell 权限
- 通过 HTTPS 提供服务的独立站店面；一键连接流程会拒绝纯 HTTP 的基础 URL
- 一个 Paymos 账户，并在控制台打开准备绑定的项目

上述版本要求声明在 `app/code/Paymos/Payment/composer.json` 中。发布压缩包已在模块自身的 `vendor/` 目录下内置 Paymos PHP SDK，手动安装时无需运行 Composer。

## 安装

从 [Releases](https://github.com/Paymos-labs/magento2/releases/latest) 或 Paymos 控制台的 **CMS integration（CMS 集成）** 面板下载 `paymos-magento2-<version>.zip`，并将其解压到 Magento 根目录。包内目录结构已按 `app/code/Paymos/Payment/…` 组织好，解压后无需移动任何文件。

```bash
bin/magento module:enable Paymos_Payment
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

`setup:upgrade` 会创建两张数据表：`paymos_payment_invoice`（每笔订单对应一条快照）和 `paymos_payment_event`（webhook 去重记录表）。

每个版本只发布一个统一压缩包，所有商户下载的文件完全相同。包内不含任何 API 密钥、API secret、项目 ID、webhook secret、OAuth token 或 device code，仅安装模块不会建立任何连接。

## 一键连接

1. 在 Paymos 控制台打开该店铺用于收款的项目。店铺将直接绑定当前打开的项目——模块内不提供项目选择器。
2. 在 Magento 后台进入 **Stores → Configuration → Sales → Payment Methods → Paymos**，点击 **Connect Paymos**。
3. 在弹出的 Paymos 标签页中核对并批准显示的店铺 URL 和项目。若浏览器拦截了弹窗，对应行会直接显示授权链接和验证码（user code）。
4. 等待 **Connection status（连接状态）** 显示为已连接，再将 **Enabled** 设为 Yes。

一次批准会同时配置好 Sandbox 和 Live 两套环境。系统会复用你唯一的活跃 Payment 密钥（若尚不存在则自动创建），并在以下地址注册 Invoice webhook：

```text
https://your-store.example/paymos/payment/callback
```

仅当回调 URL、类别和项目全部匹配时才会复用现有 webhook；同一地址上的冲突 webhook 绝不会被覆盖。设备授权仅作为一次性凭证下发通道——短时 token 用完即弃，此后所有 Merchant API 调用均采用 HMAC 签名。

凭证会经由 Magento 的 `EncryptorInterface` 加密后写入 `core_config_data`，绝不会回显到后台页面。配置页不提供粘贴 secret 的输入框；如需更换凭证，唯一的途径是重新连接。

## 配置作用域

模块的配置字段开放至 **Default Config（默认配置）** 与 **website（网站）** 作用域。Store view 会按 Magento 标准的回退机制继承配置，其结账流程和订单均以最终解析出的配置值为准。

| 配置项 | 默认值 | 用途 |
|---|---|---|
| Enabled | No | 是否在结账页显示该支付方式 |
| Title | `Pay with crypto (Paymos)` | 买家在结账页看到的支付方式名称 |
| Mode | `sandbox` | 该网站使用的凭证环境 |
| New order status | `pending_payment` | 买家付款期间赋予的订单状态 |
| Paid order status | `processing` | 付款确认后赋予的订单状态 |
| Debug logging | No | 将诊断日志写入 `var/log/paymos.log` |
| Sort order | `1` | 在所有支付方式中的排序位置 |

已连接的凭证按整套 Magento 安装共享，而非按单个网站隔离，因此第二个网站无需再次批准。切换环境只需修改 **Mode**。仅当基础 URL 变更、Payment 密钥被吊销或 webhook secret 发生轮换时，才需要重新连接。

## 订单状态流转

| Paymos 事件 | Magento 中的处理结果 |
|---|---|
| 创建账单，买家跳转支付页 | 设为所配置的 **New order status**，并添加包含账单编号的历史备注 |
| `invoice.confirming` | 状态不变；添加备注记录付款正在链上确认中 |
| `invoice.underpaid_waiting` | 状态不变；订单继续等待剩余款项 |
| `invoice.awaiting_payment` | 状态不变；此前计入的一笔付款因链上重组而失效 |
| `invoice.paid`、`invoice.paid_over` | 创建离线发票单据（offline invoice），订单进入 Processing 状态与配置的 **Paid order status**，并发送发票邮件 |
| `invoice.underpaid` | 取消订单 |
| `invoice.expired`、`invoice.cancelled` | 取消订单 |

发票单据会将最后一笔已确认转账的链上交易哈希记为 transaction id；当载荷中不包含转账记录时，则回退记录 Paymos 账单 ID。

在这张状态表之前有两道保护机制。第一，若订单总额或币种在账单创建后发生了变更，系统绝不会自动完成订单——而是写入一条请求人工审核的备注，同时仍对回调返回成功应答以停止重试阶梯。第二，若订单已付款后才收到迟到的 `confirming`、`underpaid`、`expired` 或 `cancelled` 事件，模块仅记录日志并丢弃，绝不回退订单。

## 上线前测试

1. 将 **Mode** 设为 Sandbox，并在前台店面下一笔订单。
2. 保持 Paymos 控制台处于 Sandbox 模式，打开对应账单。使用 **Pay Full**、**Pay 50%**、**Pay 150%** 和 **Cancel** 可触发与真实付款完全一致的生命周期事件，不会产生任何实际资金划转。
3. 观察 Magento 订单的状态变化、历史备注以及是否生成了发票单据。
4. 将 **Mode** 切换为 Live。Live 凭证已随同一次授权下发，无需重新连接，也无需重复配置。

Sandbox 回调载荷不包含链上转账记录，因此测试订单记录的是账单 ID，而 Live 正式订单记录的是链上交易哈希。

## Webhook 机制

回调以 `POST` 请求发送至 `/paymos/payment/callback`。该路由豁免了 Magento 的 form key 校验，因为信任边界在于 `X-Webhook-Signature` 请求头；缺少签名或签名错误的请求会收到 `401`，绝不会触及任何订单。`X-Webhook-Id` 在重试期间保持不变，并作为去重键存入 `paymos_payment_event`，重复投递会返回 `200` 且不做任何变更。

终态事件都会经过反向核验。在完成或取消订单之前，模块会从 Merchant API 重新拉取账单并与本地快照比对，任何不一致的载荷都会被拒绝。

一个完整投递周期包含约 16 小时内的 11 次尝试，因此短暂不可达的店铺依然能收到事件。作为兜底，`paymos_reconcile_invoices` 定时任务每 15 分钟从 API 拉取近期未终结的账单，`paymos_reconcile_refunds` 每分钟轮询一次处理中的退款；两者均依赖 Magento cron 正常运行。

## 退款

模块通过 Paymos Merchant API（`/v1/refunds`）支持两种原生退款模式：

- `full` —— 全额退还已付账单金额；
- `overpayment` —— 仅退还 `invoice.paid_over` 订单中多付的超额部分。

资金仅退回 Paymos 在账单付款时捕获并验证的 `first_sender` 钱包地址。不支持手动输入收款钱包地址，系统也会拒绝部分商品退款或调整金额退款。

- **全额退款（`full`）：** 在 Magento 后台打开已付款 Paymos 订单的发票，提交原生 **Credit Memo（贷项通知单）**，确保所有已开票商品全额退回且运费与金额调整均为零。`Paymos\Payment\Plugin\RefundEntry` 会拦截本次提交，在 Paymos 侧注册 `full` 退款而不提前生成本地贷项通知单，随后跳转至 **Paymos refunds** 面板。
- **超额付款退款（`overpayment`）与状态查询：** 进入 **Stores → Configuration → Sales → Payment Methods → Paymos**，在 **Paymos refunds** 字段组中输入订单 ID，点击 **Load order**，再按需使用 **Refund overpayment** 或 **Check full refund** / **Check overpayment refund**。

只有在 Paymos 通过 `/paymos/payment/callback`、手动状态查询或每分钟执行的 `paymos_reconcile_refunds` 定时任务确认退款达到 `completed` 后，Magento 才会生成贷项通知单并写入订单历史备注。在 Sandbox 模式下，无需真实划转加密资产即可模拟退款终态（`completed`、`failed`、`cancelled`）。

## 故障排查

**结账页未显示 Paymos。** 该支付方式默认处于禁用状态。请在对应前台所属的网站作用域下将 **Enabled** 设为 Yes，确认 **Connection status** 显示已连接，并刷新配置缓存——Magento 会像缓存其他配置路径一样缓存 `payment/paymos/*`。

**订单一直停留在等待付款状态。** 说明 webhook 未能送达。回调地址必须能在公网通过批准时的精确基础 URL 访问：系统会拦截回环、内网和链路本地地址，且不跟随重定向；如果店铺迁移了主机或新增了跳转，请重新连接。同时可以运行 `bin/magento cron:run`，查看对账定时任务能否先补齐状态。

**一键连接报错 "Magento base URL must use HTTPS"。** 设备授权流程读取的是默认 store view 的基础 URL，授权确认页展示的和 webhook 注册使用的都是该地址。请将其配置为 HTTPS 访问后重新发起连接。

## 相关链接

- 文档：[paymos.io/zh-hans/docs/cms-magento2](https://paymos.io/zh-hans/docs/cms-magento2)
- 源码与发布：[Paymos-labs/magento2](https://github.com/Paymos-labs/magento2)
- 技术支持：[support@paymos.io](mailto:support@paymos.io)
