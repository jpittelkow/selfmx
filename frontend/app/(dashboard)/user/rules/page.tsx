"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function EmailRulesRedirect() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/mail/settings/rules");
  }, [router]);

  return null;
}
