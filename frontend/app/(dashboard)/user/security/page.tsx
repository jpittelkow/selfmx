import { SecurityOverview } from "@/components/user/security/security-overview";
import { PasswordSection } from "@/components/user/security/password-section";
import { TwoFactorSection } from "@/components/user/security/two-factor-section";
import { PasskeySection } from "@/components/user/security/passkey-section";
import { SessionsSection } from "@/components/user/security/sessions-section";
import { ApiKeysSection } from "@/components/user/security/api-keys-section";

export default function SecurityPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Security</h1>
        <p className="text-muted-foreground">
          Manage your password, two-factor authentication, and connected accounts.
        </p>
      </div>

      <SecurityOverview />
      <PasswordSection />
      <TwoFactorSection />
      <PasskeySection />
      <SessionsSection />
      <ApiKeysSection />
    </div>
  );
}
