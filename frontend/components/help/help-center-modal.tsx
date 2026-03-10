"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useHelp } from "@/components/help/help-provider";
import { HelpSidebar } from "@/components/help/help-sidebar";
import { HelpSearch } from "@/components/help/help-search";
import { HelpArticle } from "@/components/help/help-article";
import { HelpArticleToc } from "@/components/help/help-article-toc";
import { useAuth } from "@/lib/auth";
import {
  getAllCategories,
  findArticle,
  getSearchableArticles,
} from "@/lib/help/help-content";
import { initializeSearch } from "@/lib/help/help-search";
import { extractHeadings } from "@/lib/help/help-toc";

export function HelpCenterModal() {
  const { isOpen, setIsOpen, currentArticle, setCurrentArticle } = useHelp();
  const { user } = useAuth();
  // Stabilise the permissions reference so downstream memos/effects don't
  // re-run when the auth store returns a new array with identical values.
  const rawPermissions = user?.permissions;
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const permissions = useMemo(() => rawPermissions ?? [], [JSON.stringify(rawPermissions)]);

  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [gridKey, setGridKey] = useState(0);
  const contentScrollRef = useRef<HTMLDivElement>(null);

  // Get categories based on user permissions
  const categories = useMemo(() => getAllCategories(permissions), [permissions]);

  // Initialize search index when modal opens
  useEffect(() => {
    if (isOpen) {
      const searchableItems = getSearchableArticles(permissions);
      initializeSearch(searchableItems);
    }
  }, [isOpen, permissions]);

  // Handle article selection from context (e.g., HelpLink)
  useEffect(() => {
    if (currentArticle && isOpen) {
      const result = findArticle(currentArticle, permissions);
      if (result) {
        setSelectedCategory(result.category.slug);
      }
    }
  }, [currentArticle, isOpen, permissions]);

  // Get current article content
  const articleData = useMemo(() => {
    if (!currentArticle) return null;
    return findArticle(currentArticle, permissions);
  }, [currentArticle, permissions]);

  // Extract TOC headings from the current article
  const tocHeadings = useMemo(() => {
    if (!articleData) return null;
    return extractHeadings(articleData.article.content);
  }, [articleData]);

  const handleSelectCategory = useCallback((categorySlug: string) => {
    setSelectedCategory((prev) =>
      prev === categorySlug ? null : categorySlug
    );
  }, []);

  const handleSelectArticle = useCallback(
    (articleId: string) => {
      setCurrentArticle(articleId);
      const result = findArticle(articleId, permissions);
      if (result) {
        setSelectedCategory(result.category.slug);
      }
    },
    [setCurrentArticle, permissions]
  );

  const handleBack = useCallback(() => {
    setCurrentArticle(null);
    setGridKey((k) => k + 1);
  }, [setCurrentArticle]);

  const handleSearchResult = useCallback(
    (articleId: string) => {
      handleSelectArticle(articleId);
    },
    [handleSelectArticle]
  );

  return (
    <Dialog open={isOpen} onOpenChange={setIsOpen}>
      <DialogContent
        className="max-w-6xl h-[85vh] max-h-[800px] p-0 gap-0 overflow-hidden"
        hideClose
      >
        <DialogDescription className="sr-only">
          {articleData
            ? `Help article: ${articleData.article.title}`
            : "Help Center - browse topics or search for help articles"}
        </DialogDescription>
        <DialogHeader className="px-6 py-4 border-b shrink-0">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              {currentArticle && (
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={handleBack}
                >
                  <ArrowLeft className="h-4 w-4" />
                  <span className="sr-only">Back to categories</span>
                </Button>
              )}
              <DialogTitle>
                {articleData ? articleData.article.title : "Help Center"}
              </DialogTitle>
            </div>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={() => setIsOpen(false)}
            >
              <X className="h-4 w-4" />
              <span className="sr-only">Close</span>
            </Button>
          </div>
          <div className="mt-3">
            <HelpSearch onSelectResult={handleSearchResult} />
          </div>
        </DialogHeader>

        {/* Mobile category tab bar — shown when viewing an article */}
        {currentArticle && (
          <div className="md:hidden border-b px-2 py-1.5 overflow-x-auto shrink-0">
            <div className="flex gap-1 min-w-max">
              {categories.map((category) => (
                <Button
                  key={category.slug}
                  variant={selectedCategory === category.slug ? "secondary" : "ghost"}
                  size="sm"
                  className="shrink-0 text-xs h-7 px-2.5"
                  onClick={() => {
                    if (category.articles.length > 0) {
                      handleSelectArticle(category.articles[0].id);
                    }
                  }}
                >
                  {category.icon && <category.icon className="h-3 w-3 mr-1" />}
                  {category.name}
                </Button>
              ))}
            </div>
          </div>
        )}

        <div className="flex flex-1 min-h-0">
          {/* Sidebar - hidden on mobile; category grid serves as nav */}
          <aside className="hidden md:block w-64 border-r shrink-0 overflow-hidden">
            <ScrollArea className="h-full p-4">
              <HelpSidebar
                categories={categories}
                selectedCategory={selectedCategory}
                selectedArticle={currentArticle}
                onSelectCategory={handleSelectCategory}
                onSelectArticle={handleSelectArticle}
              />
            </ScrollArea>
          </aside>

          {/* Content */}
          <main className="flex-1 min-w-0 overflow-hidden flex">
            <ScrollArea className="h-full flex-1 min-w-0" ref={contentScrollRef}>
              <div className="p-6">
                {currentArticle && articleData ? (
                  <div key={currentArticle} className="animate-in fade-in duration-200">
                    {/* Mobile inline TOC */}
                    {tocHeadings && (
                      <div className="lg:hidden mb-4">
                        <HelpArticleToc
                          headings={tocHeadings}
                          scrollContainerRef={contentScrollRef}
                          variant="inline"
                        />
                      </div>
                    )}
                    <HelpArticle content={articleData.article.content} />
                  </div>
                ) : (
                  <div key={gridKey} className="space-y-6 animate-in fade-in duration-200">
                    <div className="text-center py-8">
                      <h2 className="font-heading text-2xl font-semibold mb-2">
                        How can we help?
                      </h2>
                      <p className="text-muted-foreground">
                        Browse topics or search for specific help articles.
                      </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      {categories.map((category) => (
                        <button
                          key={category.slug}
                          type="button"
                          onClick={() => {
                            handleSelectCategory(category.slug);
                            if (category.articles.length > 0) {
                              handleSelectArticle(category.articles[0].id);
                            }
                          }}
                          className="p-4 text-left border rounded-lg hover:bg-accent/50 transition-colors group"
                        >
                          <div className="flex items-center gap-3 mb-2">
                            {category.icon && (
                              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary group-hover:bg-primary/15 transition-colors">
                                <category.icon className="h-5 w-5" />
                              </div>
                            )}
                            <span className="font-medium">{category.name}</span>
                          </div>
                          <p className="text-sm text-muted-foreground">
                            {category.articles.length} article
                            {category.articles.length !== 1 ? "s" : ""}
                          </p>
                        </button>
                      ))}
                    </div>

                    <div className="text-center text-sm text-muted-foreground">
                      <p>Press <kbd className="px-1.5 py-0.5 bg-muted rounded text-xs font-mono">?</kbd> to open this help center anytime</p>
                    </div>
                  </div>
                )}
              </div>
            </ScrollArea>

            {/* Desktop TOC sidebar */}
            {currentArticle && articleData && tocHeadings && (
              <aside className="hidden lg:block w-48 border-l p-4 overflow-y-auto shrink-0">
                <HelpArticleToc
                  headings={tocHeadings}
                  scrollContainerRef={contentScrollRef}
                  variant="sidebar"
                />
              </aside>
            )}
          </main>
        </div>
      </DialogContent>
    </Dialog>
  );
}
