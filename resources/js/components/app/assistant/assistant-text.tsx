import { Fragment, type ReactNode } from 'react';

/** `**bold**` inside a line; everything else is plain text (never HTML). */
function inline(text: string): ReactNode[] {
    return text.split(/(\*\*[^*]+\*\*)/g).map((part, i) =>
        part.startsWith('**') && part.endsWith('**') && part.length > 4 ? (
            <strong key={i} className="text-foreground font-semibold">
                {part.slice(2, -2)}
            </strong>
        ) : (
            <Fragment key={i}>{part}</Fragment>
        ),
    );
}

/**
 * The assistant's answer as light, safe formatting: paragraphs, bullet and numbered lists, simple headings and bold.
 * The model's text is rendered as text nodes only, so nothing in it can become markup or a link.
 */
export function AssistantText({ text }: { text: string }) {
    const blocks = text
        .replace(/\r/g, '')
        .split(/\n{2,}/)
        .filter((block) => block.trim() !== '');

    return (
        <div className="text-foreground space-y-2.5 text-sm leading-relaxed">
            {blocks.map((block, b) => {
                const lines = block.split('\n').filter((line) => line.trim() !== '');
                const bullets = lines.every((line) => /^\s*([-*•]|\d+[.)])\s+/.test(line));

                if (bullets) {
                    const ordered = /^\s*\d+[.)]/.test(lines[0] ?? '');
                    const List = ordered ? 'ol' : 'ul';

                    return (
                        <List key={b} className={ordered ? 'list-decimal space-y-1 pl-5' : 'list-disc space-y-1 pl-5'}>
                            {lines.map((line, i) => (
                                <li key={i} className="tabular-nums">
                                    {inline(line.replace(/^\s*([-*•]|\d+[.)])\s+/, ''))}
                                </li>
                            ))}
                        </List>
                    );
                }

                const heading = /^#{1,4}\s+(.*)$/.exec(lines[0] ?? '');
                if (heading && lines.length === 1) {
                    return (
                        <p key={b} className="font-semibold">
                            {inline(heading[1])}
                        </p>
                    );
                }

                return (
                    <p key={b} className="tabular-nums">
                        {lines.map((line, i) => (
                            <Fragment key={i}>
                                {i > 0 && <br />}
                                {inline(line.replace(/^#{1,4}\s+/, ''))}
                            </Fragment>
                        ))}
                    </p>
                );
            })}
        </div>
    );
}
