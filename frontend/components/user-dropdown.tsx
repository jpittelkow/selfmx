"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth, isAdminUser } from "@/lib/auth";
import { api } from "@/lib/api";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { User, Settings, Shield, LogOut, ChevronDown, Info, Sparkles, HelpCircle, ListFilter } from "lucide-react";
import { AboutDialog } from "@/components/about-dialog";
import { useWizard } from "@/components/onboarding/wizard-provider";
import { useHelp } from "@/components/help/help-provider";

function getInitials(name: string) {
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
}

export function UserDropdown() {
  const { user } = useAuth();
  const router = useRouter();
  const [aboutOpen, setAboutOpen] = useState(false);
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);
  const { resetWizard } = useWizard();
  const { setIsOpen: setHelpOpen } = useHelp();

  if (!user) {
    return null;
  }

  const handleLogout = () => {
    useAuth.setState({ user: null, error: null });
    router.push("/login");
    api.post("/auth/logout").catch(() => {});
  };

  const handleShowWizard = async () => {
    await resetWizard();
  };

  return (
    <>
      <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label={`${user.name} account menu`}
          className="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 transition-colors"
        >
          <Avatar className="h-8 w-8 border-2 border-background">
            <AvatarImage src={user.avatar || undefined} />
            <AvatarFallback className="text-xs font-semibold">
              {getInitials(user.name)}
            </AvatarFallback>
          </Avatar>
          <div className="hidden sm:flex items-center gap-2">
            <div className="flex flex-col items-start">
              <span className="text-sm font-medium leading-tight">{user.name}</span>
              {isAdminUser(user) && (
                <Badge variant="secondary" className="text-xs h-4 px-1.5 mt-0.5">
                  Admin
                </Badge>
              )}
            </div>
            <ChevronDown className="h-4 w-4 text-muted-foreground" />
          </div>
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel>
          <div className="flex flex-col space-y-1">
            <p className="text-sm font-medium leading-none">{user.name}</p>
            <p className="text-xs leading-none text-muted-foreground">
              {user.email}
            </p>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => router.push("/user/profile")}>
          <User className="mr-2 h-4 w-4" />
          My Profile
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => router.push("/user/preferences")}>
          <Settings className="mr-2 h-4 w-4" />
          Preferences
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => router.push("/user/security")}>
          <Shield className="mr-2 h-4 w-4" />
          Security
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => router.push("/mail/settings")}>
          <ListFilter className="mr-2 h-4 w-4" />
          Mail Settings
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={handleShowWizard}>
          <Sparkles className="mr-2 h-4 w-4" />
          Getting Started
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => setHelpOpen(true)}>
          <HelpCircle className="mr-2 h-4 w-4" />
          Help Center
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => setAboutOpen(true)}>
          <Info className="mr-2 h-4 w-4" />
          About
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => setShowLogoutConfirm(true)}>
          <LogOut className="mr-2 h-4 w-4" />
          Sign Out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
    <AlertDialog open={showLogoutConfirm} onOpenChange={setShowLogoutConfirm}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Sign out?</AlertDialogTitle>
          <AlertDialogDescription>
            Are you sure you want to sign out of your account?
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            onClick={handleLogout}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            Sign Out
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
    <AboutDialog open={aboutOpen} onOpenChange={setAboutOpen} />
    </>
  );
}
