"use client";

import { useState } from "react";
import { usePayments, type Payment } from "@/lib/stripe";
import { useAuth, isAdminUser } from "@/lib/auth";
import { usePermission } from "@/lib/use-permission";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { CreditCard, ChevronLeft, ChevronRight } from "lucide-react";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatAmount(cents: number, currency: string): string {
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: currency.toUpperCase(),
    }).format(cents / 100);
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency.toUpperCase()}`;
  }
}

function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) return "—";
  return new Date(dateStr).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

const statusVariant: Record<
  string,
  "default" | "success" | "warning" | "destructive" | "secondary" | "outline"
> = {
  succeeded: "success",
  failed: "destructive",
  requires_payment_method: "warning",
  requires_confirmation: "warning",
  requires_action: "warning",
  processing: "warning",
  canceled: "secondary",
  refunded: "outline",
  partially_refunded: "outline",
};

function StatusBadge({ status }: { status: string }) {
  const variant = statusVariant[status] ?? "secondary";
  const label = status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
  return <Badge variant={variant}>{label}</Badge>;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function PaymentHistoryPage() {
  const { user } = useAuth();
  const canManage = usePermission("payments.manage") || isAdminUser(user);
  const [isAdmin, setIsAdmin] = useState(canManage);
  const { data, isLoading, page, setPage } = usePayments(isAdmin);

  if (isLoading && !data) {
    return <SettingsPageSkeleton />;
  }

  const payments = data?.data ?? [];
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
            Payment History
          </h1>
          <p className="text-muted-foreground mt-1">
            {total} payment{total !== 1 ? "s" : ""} found
          </p>
        </div>
        {canManage && (
          <Tabs
            value={isAdmin ? "all" : "mine"}
            onValueChange={(v) => {
              setIsAdmin(v === "all");
              setPage(1);
            }}
          >
            <TabsList>
              <TabsTrigger value="mine">My Payments</TabsTrigger>
              <TabsTrigger value="all">All Payments</TabsTrigger>
            </TabsList>
          </Tabs>
        )}
      </div>

      {/* Table */}
      {payments.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <CreditCard className="h-12 w-12 text-muted-foreground mb-4" />
          <h3 className="text-lg font-medium">No payments yet</h3>
          <p className="text-sm text-muted-foreground mt-1">
            Payments will appear here once transactions are processed.
          </p>
        </div>
      ) : (
        <>
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead>Status</TableHead>
                  {isAdmin && <TableHead className="text-right">Fee</TableHead>}
                  {isAdmin && <TableHead>User</TableHead>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {payments.map((payment: Payment) => (
                  <TableRow key={payment.id}>
                    <TableCell className="whitespace-nowrap">
                      {formatDate(payment.created_at)}
                    </TableCell>
                    <TableCell className="max-w-[200px] truncate">
                      {payment.description || "—"}
                    </TableCell>
                    <TableCell className="text-right whitespace-nowrap font-medium">
                      {formatAmount(payment.amount, payment.currency)}
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={payment.status} />
                    </TableCell>
                    {isAdmin && (
                      <TableCell className="text-right whitespace-nowrap text-muted-foreground">
                        {payment.application_fee_amount != null
                          ? formatAmount(
                              payment.application_fee_amount,
                              payment.currency
                            )
                          : "—"}
                      </TableCell>
                    )}
                    {isAdmin && (
                      <TableCell className="max-w-[200px] truncate">
                        {payment.user?.name || payment.user?.email || "—"}
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {/* Pagination */}
          {lastPage > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                Page {page} of {lastPage}
              </p>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage(page - 1)}
                >
                  <ChevronLeft className="h-4 w-4" />
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= lastPage}
                  onClick={() => setPage(page + 1)}
                >
                  Next
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
