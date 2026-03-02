"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { Clock, Loader2 } from "lucide-react";

interface SnoozePickerProps {
  emailId: number;
  onSnoozed: () => void;
}

function getPresets(): { label: string; getDate: () => Date }[] {
  return [
    {
      label: "Later today",
      getDate: () => {
        const d = new Date();
        d.setHours(d.getHours() + 3, 0, 0, 0);
        return d;
      },
    },
    {
      label: "Tomorrow morning",
      getDate: () => {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        d.setHours(8, 0, 0, 0);
        return d;
      },
    },
    {
      label: "This weekend",
      getDate: () => {
        const d = new Date();
        const day = d.getDay();
        const daysUntilSat = day === 6 ? 7 : 6 - day;
        d.setDate(d.getDate() + daysUntilSat);
        d.setHours(8, 0, 0, 0);
        return d;
      },
    },
    {
      label: "Next week",
      getDate: () => {
        const d = new Date();
        const day = d.getDay();
        const daysUntilMon = day === 0 ? 1 : 8 - day;
        d.setDate(d.getDate() + daysUntilMon);
        d.setHours(8, 0, 0, 0);
        return d;
      },
    },
  ];
}

export function SnoozePicker({ emailId, onSnoozed }: SnoozePickerProps) {
  const [open, setOpen] = useState(false);
  const [isSnoozing, setIsSnoozing] = useState(false);
  const [showCustom, setShowCustom] = useState(false);
  const [customDate, setCustomDate] = useState<Date | undefined>(undefined);
  const [customTime, setCustomTime] = useState("08:00");

  const handleSnooze = async (snoozeUntil: Date) => {
    setIsSnoozing(true);
    try {
      await api.post(`/email/messages/${emailId}/snooze`, {
        snooze_until: snoozeUntil.toISOString(),
      });
      toast.success(`Snoozed until ${snoozeUntil.toLocaleString()}`);
      setOpen(false);
      setShowCustom(false);
      onSnoozed();
    } catch {
      toast.error("Failed to snooze email");
    } finally {
      setIsSnoozing(false);
    }
  };

  const handleCustomSnooze = () => {
    if (!customDate) return;
    const d = new Date(customDate);
    const [h, m] = customTime.split(":").map(Number);
    d.setHours(h, m, 0, 0);
    handleSnooze(d);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" title="Snooze" disabled={isSnoozing}>
          {isSnoozing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Clock className="h-4 w-4" />}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-64 p-2" align="end">
        {showCustom ? (
          <div className="space-y-3 p-1">
            <Calendar
              mode="single"
              selected={customDate}
              onSelect={setCustomDate}
              disabled={(date) => date < new Date()}
            />
            <div className="flex items-center gap-2">
              <label className="text-sm font-medium">Time:</label>
              <Input
                type="time"
                value={customTime}
                onChange={(e) => setCustomTime(e.target.value)}
                className="w-32"
              />
            </div>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setShowCustom(false)} className="flex-1">
                Back
              </Button>
              <Button size="sm" onClick={handleCustomSnooze} disabled={!customDate} className="flex-1">
                Snooze
              </Button>
            </div>
          </div>
        ) : (
          <div className="space-y-1">
            {getPresets().map((preset) => (
              <Button
                key={preset.label}
                variant="ghost"
                className="w-full justify-start text-sm"
                onClick={() => handleSnooze(preset.getDate())}
              >
                {preset.label}
              </Button>
            ))}
            <Button
              variant="ghost"
              className="w-full justify-start text-sm"
              onClick={() => setShowCustom(true)}
            >
              Pick date & time...
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}
