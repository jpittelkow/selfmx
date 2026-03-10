"use client";

import { useMemo, type HTMLAttributes, type PropsWithChildren } from "react";
import ReactMarkdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import rehypeHighlight from "rehype-highlight";
import bash from "highlight.js/lib/languages/bash";
import javascript from "highlight.js/lib/languages/javascript";
import json from "highlight.js/lib/languages/json";
import graphql from "highlight.js/lib/languages/graphql";
import { cn } from "@/lib/utils";
import { slugify, childrenToText } from "@/lib/help/help-toc";

const highlightLanguages = { bash, javascript, json, graphql };

type HeadingProps = PropsWithChildren<
  HTMLAttributes<HTMLHeadingElement> & { node?: unknown }
>;

function makeHeading(Tag: "h1" | "h2" | "h3") {
  const Heading = ({ children, node: _node, ...props }: HeadingProps) => (
    <Tag id={slugify(childrenToText(children))} {...props}>{children}</Tag>
  );
  Heading.displayName = Tag.toUpperCase();
  return Heading;
}

const headingComponents: Partial<Components> = {
  h1: makeHeading("h1"),
  h2: makeHeading("h2"),
  h3: makeHeading("h3"),
};

interface HelpArticleProps {
  content: string;
  className?: string;
}

export function HelpArticle({ content, className }: HelpArticleProps) {
  const rehypePlugins = useMemo(
    () => [[rehypeHighlight, { languages: highlightLanguages, detect: false }]] as Parameters<typeof ReactMarkdown>[0]["rehypePlugins"],
    []
  );

  return (
    <div
      className={cn(
        "help-article-prose prose prose-sm dark:prose-invert max-w-none break-words",
        "prose-headings:font-heading prose-headings:font-semibold prose-headings:tracking-tight",
        "prose-h1:text-2xl prose-h2:text-xl prose-h3:text-lg",
        "prose-p:text-foreground prose-p:leading-relaxed",
        "prose-a:text-primary prose-a:no-underline hover:prose-a:underline",
        "prose-strong:text-foreground prose-strong:font-medium",
        "prose-ul:text-foreground prose-ol:text-foreground",
        "prose-li:marker:text-muted-foreground",
        "prose-pre:bg-muted prose-pre:border prose-pre:rounded-lg",
        "prose-pre:text-[0.8125rem] prose-pre:leading-relaxed",
        "prose-pre:max-w-full prose-pre:overflow-x-auto",
        "prose-code:font-mono prose-code:text-[0.8125rem]",
        "[&_:not(pre)>code]:bg-muted [&_:not(pre)>code]:px-1.5 [&_:not(pre)>code]:py-0.5",
        "[&_:not(pre)>code]:rounded [&_:not(pre)>code]:text-[0.8125rem]",
        "[&_:not(pre)>code]:before:content-none [&_:not(pre)>code]:after:content-none",
        "prose-table:text-sm",
        className
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        rehypePlugins={rehypePlugins}
        components={headingComponents}
      >
        {content}
      </ReactMarkdown>
    </div>
  );
}
