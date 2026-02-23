# Sourdough Commercial License

Copyright (c) 2026 Sourdough

## Scope

This license applies to the following files and directories:

**Backend:**

- `backend/app/Services/Stripe/` (all files)
- `backend/app/Http/Controllers/Api/StripeSettingController.php`
- `backend/app/Http/Controllers/Api/StripeConnectController.php`
- `backend/app/Http/Controllers/Api/StripeConnectCallbackController.php`
- `backend/app/Http/Controllers/Api/StripePaymentController.php`
- `backend/app/Http/Controllers/Api/StripeWebhookController.php`
- `backend/app/Models/Payment.php`
- `backend/app/Models/StripeCustomer.php`
- `backend/app/Models/StripeWebhookEvent.php`
- `backend/config/stripe.php`
- `backend/database/migrations/2026_02_21_000001_create_stripe_customers_table.php`
- `backend/database/migrations/2026_02_21_000002_create_payments_table.php`
- `backend/database/migrations/2026_02_21_000003_create_stripe_webhook_events_table.php`

**Frontend:**

- `frontend/lib/stripe.ts`
- `frontend/app/(dashboard)/configuration/stripe/` (all files)
- `frontend/app/(dashboard)/configuration/payments/` (all files)

## Terms

### Permitted Use (Free)

You may use, copy, modify, and distribute the files listed above **free of charge** provided your Stripe integration uses **Stripe Connect** with the Sourdough platform as the Connect platform — i.e., the application fee flows to Sourdough via Stripe's `application_fee_amount` on destination charges.

### Commercial License Required

If you wish to use these files with **direct Stripe integration** — bypassing Stripe Connect, removing the application fee, or using your own platform account — you must obtain a commercial license. Contact the Sourdough maintainers for licensing terms.

### Summary

- **Fork operators who connect via Stripe Connect:** free to use.
- **Fork operators who want direct Stripe without Connect:** commercial license required.
- **All other Sourdough files:** remain MIT licensed (see root `LICENSE`).

## Disclaimer

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
