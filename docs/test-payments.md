# Stripe and Razorpay test checkout

The only product is Framekeep Pro at USD $20 per year. Use a separate test environment and test database. Test purchases are kept separate from regular Pro entitlements. The app rejects live credentials and ignores test entitlements whenever `FRAMEKEEP_TEST_PAYMENTS=false`.

## Stripe Sandbox

1. In the Stripe Sandbox, create a USD $20 recurring price with a yearly interval. Use its Price ID for `STRIPE_TEST_YEARLY_PRICE_ID`. For `STRIPE_TEST_SECRET_KEY`, use a restricted sandbox key with Checkout Sessions write access and read access to Prices, Subscriptions, and Invoices.
2. Register `https://<test-host>/api/v1/webhooks/payments/stripe` and save its signing secret as `STRIPE_TEST_WEBHOOK_SECRET`. Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `invoice.paid`, `invoice.payment_failed`, and the `customer.subscription.created`, `customer.subscription.updated`, and `customer.subscription.deleted` events.
3. Enable the app's `FRAMEKEEP_TEST_PAYMENTS` flag and rebuild its Laravel config cache. Sign in with a Free test user, choose **Test with Stripe** and complete checkout using Stripe's test details. After the webhook succeeds, confirm Pro status on `GET /api/v1/me` and try a ZIP export.

## Razorpay Test Mode

1. In Razorpay Test Mode, create a subscription plan with period `yearly`, interval `1`, currency `USD`, and amount `2000` in cents. If Razorpay does not allow USD subscriptions for the account, Razorpay remains unavailable for this plan until that capability is enabled.
2. Set `RAZORPAY_TEST_YEARLY_PLAN_ID`, `RAZORPAY_TEST_KEY_ID`, and `RAZORPAY_TEST_KEY_SECRET`. Register `https://<test-host>/api/v1/webhooks/payments/razorpay` and set `RAZORPAY_TEST_WEBHOOK_SECRET`. Subscribe to `subscription.charged`, `subscription.activated`, `subscription.cancelled`, `subscription.halted`, `subscription.completed`, `subscription.paused`, `subscription.resumed`, and `subscription.pending`.
3. With test payments enabled, sign in with a second Free test user, choose **Test with Razorpay**, and use the test method offered by the hosted checkout. Confirm Pro status only after Razorpay reports a paid subscription and a current billing period.

Never commit credentials. Keep `FRAMEKEEP_TEST_PAYMENTS=false` on the public Hostinger site.
