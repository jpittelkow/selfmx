"use client";

import { useState, useEffect, useCallback } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useMailData } from "@/lib/mail-data-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { SettingsPageSkeleton } from "@/components/ui/settings-page-skeleton";
import { EmptyState } from "@/components/ui/empty-state";
import { ListFilter, Plus, Pencil, Trash2, GripVertical, Loader2, X } from "lucide-react";

interface Condition {
  field: string;
  operator: string;
  value: string | number | boolean;
}

interface Action {
  type: string;
  value?: string | number | null;
}

interface EmailRule {
  id: number;
  name: string;
  is_active: boolean;
  match_mode: "all" | "any";
  conditions: Condition[];
  actions: Action[];
  sort_order: number;
  stop_processing: boolean;
  created_at: string;
}

const FIELD_OPTIONS = [
  { value: "from", label: "From" },
  { value: "to", label: "To" },
  { value: "cc", label: "CC" },
  { value: "subject", label: "Subject" },
  { value: "body", label: "Body" },
  { value: "has_attachment", label: "Has Attachment" },
  { value: "spam_score", label: "Spam Score" },
];

const STRING_OPERATORS = [
  { value: "contains", label: "contains" },
  { value: "not_contains", label: "does not contain" },
  { value: "equals", label: "equals" },
  { value: "not_equals", label: "does not equal" },
  { value: "starts_with", label: "starts with" },
  { value: "ends_with", label: "ends with" },
];

const NUMERIC_OPERATORS = [
  { value: "greater_than", label: "greater than" },
  { value: "less_than", label: "less than" },
  { value: "equals", label: "equals" },
];

const BOOL_OPERATORS = [
  { value: "equals", label: "is" },
];

const ACTION_TYPES = [
  { value: "label", label: "Apply Label" },
  { value: "archive", label: "Archive" },
  { value: "mark_read", label: "Mark as Read" },
  { value: "mark_spam", label: "Mark as Spam" },
  { value: "trash", label: "Move to Trash" },
  { value: "forward", label: "Forward to" },
];

function getOperatorsForField(field: string) {
  if (field === "spam_score") return NUMERIC_OPERATORS;
  if (field === "has_attachment") return BOOL_OPERATORS;
  return STRING_OPERATORS;
}

function actionNeedsValue(type: string) {
  return type === "label" || type === "forward";
}

function getConditionSummary(conditions: Condition[], matchMode: string): string {
  const parts = conditions.map((c) => {
    const fieldLabel = FIELD_OPTIONS.find((f) => f.value === c.field)?.label || c.field;
    return `${fieldLabel} ${c.operator.replace(/_/g, " ")} "${c.value}"`;
  });
  return parts.join(matchMode === "all" ? " AND " : " OR ");
}

function getActionSummary(action: Action): string {
  const typeLabel = ACTION_TYPES.find((t) => t.value === action.type)?.label || action.type;
  if (action.value) return `${typeLabel}: ${action.value}`;
  return typeLabel;
}

export default function EmailRulesPage() {
  const { labels } = useMailData();
  const [rules, setRules] = useState<EmailRule[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingRule, setEditingRule] = useState<EmailRule | null>(null);
  const [showEditor, setShowEditor] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  // Editor state
  const [editorName, setEditorName] = useState("");
  const [editorMatchMode, setEditorMatchMode] = useState<"all" | "any">("all");
  const [editorConditions, setEditorConditions] = useState<Condition[]>([{ field: "from", operator: "contains", value: "" }]);
  const [editorActions, setEditorActions] = useState<Action[]>([{ type: "mark_read" }]);
  const [editorStopProcessing, setEditorStopProcessing] = useState(false);

  const fetchRules = useCallback(async () => {
    try {
      const res = await api.get<{ rules: EmailRule[] }>("/email/rules");
      setRules(res.data.rules);
    } catch {
      toast.error("Failed to load email rules");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchRules();
  }, [fetchRules]);

  const openCreateDialog = () => {
    setEditingRule(null);
    setEditorName("");
    setEditorMatchMode("all");
    setEditorConditions([{ field: "from", operator: "contains", value: "" }]);
    setEditorActions([{ type: "mark_read" }]);
    setEditorStopProcessing(false);
    setShowEditor(true);
  };

  const openEditDialog = (rule: EmailRule) => {
    setEditingRule(rule);
    setEditorName(rule.name);
    setEditorMatchMode(rule.match_mode);
    setEditorConditions([...rule.conditions]);
    setEditorActions([...rule.actions]);
    setEditorStopProcessing(rule.stop_processing);
    setShowEditor(true);
  };

  const handleSave = async () => {
    if (!editorName.trim()) {
      toast.error("Rule name is required");
      return;
    }
    if (editorConditions.some((c) => c.field !== "has_attachment" && !c.value && c.value !== 0)) {
      toast.error("All conditions must have a value");
      return;
    }
    if (editorActions.some((a) => actionNeedsValue(a.type) && !a.value)) {
      toast.error("Some actions require a value");
      return;
    }

    setIsSaving(true);
    try {
      const payload = {
        name: editorName,
        match_mode: editorMatchMode,
        conditions: editorConditions,
        actions: editorActions,
        stop_processing: editorStopProcessing,
      };

      if (editingRule) {
        await api.put(`/email/rules/${editingRule.id}`, payload);
        toast.success("Rule updated");
      } else {
        await api.post("/email/rules", payload);
        toast.success("Rule created");
      }

      setShowEditor(false);
      fetchRules();
    } catch {
      toast.error("Failed to save rule");
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async (id: number) => {
    setDeletingId(id);
    try {
      await api.delete(`/email/rules/${id}`);
      toast.success("Rule deleted");
      fetchRules();
    } catch {
      toast.error("Failed to delete rule");
    } finally {
      setDeletingId(null);
    }
  };

  const handleToggleActive = async (rule: EmailRule) => {
    try {
      await api.put(`/email/rules/${rule.id}`, { is_active: !rule.is_active });
      fetchRules();
    } catch {
      toast.error("Failed to update rule");
    }
  };

  const addCondition = () => {
    setEditorConditions([...editorConditions, { field: "from", operator: "contains", value: "" }]);
  };

  const removeCondition = (index: number) => {
    if (editorConditions.length <= 1) return;
    setEditorConditions(editorConditions.filter((_, i) => i !== index));
  };

  const updateCondition = (index: number, updates: Partial<Condition>) => {
    const newConditions = [...editorConditions];
    newConditions[index] = { ...newConditions[index], ...updates };
    // Reset operator when field changes
    if (updates.field) {
      const operators = getOperatorsForField(updates.field);
      if (!operators.some((o) => o.value === newConditions[index].operator)) {
        newConditions[index].operator = operators[0].value;
      }
      if (updates.field === "has_attachment") {
        newConditions[index].value = true;
      } else if (typeof newConditions[index].value === "boolean") {
        newConditions[index].value = "";
      }
    }
    setEditorConditions(newConditions);
  };

  const addAction = () => {
    setEditorActions([...editorActions, { type: "mark_read" }]);
  };

  const removeAction = (index: number) => {
    if (editorActions.length <= 1) return;
    setEditorActions(editorActions.filter((_, i) => i !== index));
  };

  const updateAction = (index: number, updates: Partial<Action>) => {
    const newActions = [...editorActions];
    newActions[index] = { ...newActions[index], ...updates };
    if (updates.type && !actionNeedsValue(updates.type)) {
      newActions[index].value = null;
    }
    setEditorActions(newActions);
  };

  if (isLoading) return <SettingsPageSkeleton />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Email Rules</h2>
          <p className="text-muted-foreground">
            Automatically process incoming emails based on conditions.
          </p>
        </div>
        <Button onClick={openCreateDialog}>
          <Plus className="h-4 w-4 mr-2" />
          Create Rule
        </Button>
      </div>

      {rules.length === 0 ? (
        <Card>
          <CardContent className="py-12">
            <EmptyState
              icon={ListFilter}
              title="No email rules"
              description="Create rules to automatically label, archive, or forward incoming emails."
            />
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {rules.map((rule) => (
            <Card key={rule.id} className={!rule.is_active ? "opacity-60" : ""}>
              <CardContent className="py-4">
                <div className="flex items-start gap-3">
                  <GripVertical className="h-5 w-5 text-muted-foreground mt-0.5 shrink-0 cursor-grab" />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-medium">{rule.name}</span>
                      {!rule.is_active && <Badge variant="secondary">Disabled</Badge>}
                      {rule.stop_processing && <Badge variant="outline">Stop</Badge>}
                    </div>
                    <p className="text-sm text-muted-foreground truncate">
                      {getConditionSummary(rule.conditions, rule.match_mode)}
                    </p>
                    <div className="flex gap-1.5 mt-1.5">
                      {rule.actions.map((action, i) => (
                        <Badge key={i} variant="secondary" className="text-xs">
                          {getActionSummary(action)}
                        </Badge>
                      ))}
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Switch
                      checked={rule.is_active}
                      onCheckedChange={() => handleToggleActive(rule)}
                    />
                    <Button variant="ghost" size="icon" onClick={() => openEditDialog(rule)}>
                      <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => handleDelete(rule.id)}
                      disabled={deletingId === rule.id}
                    >
                      {deletingId === rule.id ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Trash2 className="h-4 w-4 text-destructive" />
                      )}
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Rule Editor Dialog */}
      <Dialog open={showEditor} onOpenChange={setShowEditor}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingRule ? "Edit Rule" : "Create Rule"}</DialogTitle>
            <DialogDescription>
              Define conditions and actions for automatic email processing.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-6">
            {/* Name */}
            <div>
              <Label htmlFor="rule-name">Rule Name</Label>
              <Input
                id="rule-name"
                value={editorName}
                onChange={(e) => setEditorName(e.target.value)}
                placeholder="e.g., Archive newsletters"
              />
            </div>

            {/* Match Mode */}
            <div>
              <Label>Match Mode</Label>
              <Select value={editorMatchMode} onValueChange={(v) => setEditorMatchMode(v as "all" | "any")}>
                <SelectTrigger className="w-48">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Match ALL conditions</SelectItem>
                  <SelectItem value="any">Match ANY condition</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Conditions */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <Label>Conditions</Label>
                <Button variant="outline" size="sm" onClick={addCondition}>
                  <Plus className="h-3 w-3 mr-1" />
                  Add
                </Button>
              </div>
              <div className="space-y-2">
                {editorConditions.map((condition, i) => (
                  <div key={i} className="flex gap-2 items-center">
                    <Select value={condition.field} onValueChange={(v) => updateCondition(i, { field: v })}>
                      <SelectTrigger className="w-36">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {FIELD_OPTIONS.map((f) => (
                          <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <Select value={condition.operator} onValueChange={(v) => updateCondition(i, { operator: v })}>
                      <SelectTrigger className="w-40">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {getOperatorsForField(condition.field).map((o) => (
                          <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {condition.field === "has_attachment" ? (
                      <Select
                        value={condition.value ? "true" : "false"}
                        onValueChange={(v) => updateCondition(i, { value: v === "true" })}
                      >
                        <SelectTrigger className="flex-1">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="true">Yes</SelectItem>
                          <SelectItem value="false">No</SelectItem>
                        </SelectContent>
                      </Select>
                    ) : (
                      <Input
                        className="flex-1"
                        value={String(condition.value)}
                        onChange={(e) => updateCondition(i, { value: e.target.value })}
                        placeholder="Value"
                        type={condition.field === "spam_score" ? "number" : "text"}
                      />
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => removeCondition(i)}
                      disabled={editorConditions.length <= 1}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            {/* Actions */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <Label>Actions</Label>
                <Button variant="outline" size="sm" onClick={addAction}>
                  <Plus className="h-3 w-3 mr-1" />
                  Add
                </Button>
              </div>
              <div className="space-y-2">
                {editorActions.map((action, i) => (
                  <div key={i} className="flex gap-2 items-center">
                    <Select value={action.type} onValueChange={(v) => updateAction(i, { type: v })}>
                      <SelectTrigger className="w-44">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {ACTION_TYPES.map((t) => (
                          <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {action.type === "label" ? (
                      <Select
                        value={action.value ? String(action.value) : ""}
                        onValueChange={(v) => updateAction(i, { value: parseInt(v) })}
                      >
                        <SelectTrigger className="flex-1">
                          <SelectValue placeholder="Select label" />
                        </SelectTrigger>
                        <SelectContent>
                          {labels.map((l) => (
                            <SelectItem key={l.id} value={String(l.id)}>{l.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    ) : action.type === "forward" ? (
                      <Input
                        className="flex-1"
                        value={String(action.value || "")}
                        onChange={(e) => updateAction(i, { value: e.target.value })}
                        placeholder="email@example.com"
                        type="email"
                      />
                    ) : (
                      <div className="flex-1" />
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => removeAction(i)}
                      disabled={editorActions.length <= 1}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            {/* Stop Processing */}
            <div className="flex items-center gap-3">
              <Switch
                checked={editorStopProcessing}
                onCheckedChange={setEditorStopProcessing}
              />
              <Label>Stop processing further rules when this rule matches</Label>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowEditor(false)}>Cancel</Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              {editingRule ? "Save Changes" : "Create Rule"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
