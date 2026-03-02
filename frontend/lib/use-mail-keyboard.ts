import { useEffect, useCallback, useRef } from "react";

interface MailKeyboardActions {
  onNextThread?: () => void;
  onPrevThread?: () => void;
  onArchive?: () => void;
  onStar?: () => void;
  onTrash?: () => void;
  onReply?: () => void;
  onReplyAll?: () => void;
  onForward?: () => void;
  onCompose?: () => void;
  onMarkUnread?: () => void;
  onEscape?: () => void;
  onSearch?: () => void;
  onToggleRead?: () => void;
}

/**
 * Keyboard shortcuts for the mail interface.
 *
 * Shortcuts:
 * - j/k: Next/previous thread
 * - e: Archive
 * - s: Star/unstar
 * - #: Trash
 * - r: Reply
 * - a: Reply all
 * - f: Forward
 * - c: Compose
 * - u: Mark unread
 * - Escape: Deselect / go back
 * - /: Focus search
 */
export function useMailKeyboard(actions: MailKeyboardActions) {
  const actionsRef = useRef(actions);
  actionsRef.current = actions;

  const handleKeyDown = useCallback((e: KeyboardEvent) => {
    const target = e.target as HTMLElement;
    const tagName = target.tagName.toLowerCase();

    // Don't fire shortcuts when user is typing in an input, textarea, or contenteditable
    if (
      tagName === "input" ||
      tagName === "textarea" ||
      tagName === "select" ||
      target.isContentEditable ||
      target.closest("[role='dialog']") ||
      target.closest("[data-radix-popper-content-wrapper]")
    ) {
      // Only allow Escape through
      if (e.key !== "Escape") return;
    }

    // Don't intercept if modifier keys are held (except Shift for #)
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    switch (e.key) {
      case "j":
        e.preventDefault();
        actionsRef.current.onNextThread?.();
        break;
      case "k":
        e.preventDefault();
        actionsRef.current.onPrevThread?.();
        break;
      case "e":
        e.preventDefault();
        actionsRef.current.onArchive?.();
        break;
      case "s":
        e.preventDefault();
        actionsRef.current.onStar?.();
        break;
      case "#":
        e.preventDefault();
        actionsRef.current.onTrash?.();
        break;
      case "r":
        e.preventDefault();
        actionsRef.current.onReply?.();
        break;
      case "a":
        e.preventDefault();
        actionsRef.current.onReplyAll?.();
        break;
      case "f":
        e.preventDefault();
        actionsRef.current.onForward?.();
        break;
      case "c":
        e.preventDefault();
        actionsRef.current.onCompose?.();
        break;
      case "u":
        e.preventDefault();
        actionsRef.current.onMarkUnread?.();
        break;
      case "Escape":
        actionsRef.current.onEscape?.();
        break;
      case "/":
        e.preventDefault();
        actionsRef.current.onSearch?.();
        break;
    }
  }, []);

  useEffect(() => {
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [handleKeyDown]);
}
