import { ReactNode } from "react";

export default function MailLayout({ children }: { children: ReactNode }) {
  return <div className="h-[calc(100vh-3.5rem)]">{children}</div>;
}
