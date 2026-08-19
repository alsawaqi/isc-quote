<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { Mark, mergeAttributes } from '@tiptap/core';
import Underline from '@tiptap/extension-underline';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Heading2, Highlighter, Italic, List, ListOrdered, Quote, Redo2, Strikethrough, Underline as UnderlineIcon, Undo2 } from 'lucide-vue-next';

const props = defineProps<{
    modelValue: string;
    placeholder?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const Highlight = Mark.create({
    name: 'highlight',

    parseHTML() {
        return [
            { tag: 'mark' },
            {
                style: 'background-color',
                getAttrs: (value) => (value ? {} : false),
            },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return ['mark', mergeAttributes(HTMLAttributes, { class: 'highlight', style: 'background-color: #fff59d;' }), 0];
    },
});

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            heading: {
                levels: [2, 3],
            },
            underline: false,
        }),
        Underline,
        Highlight,
    ],
    editorProps: {
        attributes: {
            class: 'rich-editor-surface',
        },
    },
    onUpdate: ({ editor: activeEditor }) => {
        emit('update:modelValue', activeEditor.getHTML());
    },
});

watch(
    () => props.modelValue,
    (value) => {
        const activeEditor = editor.value;

        if (activeEditor && activeEditor.getHTML() !== value) {
            activeEditor.commands.setContent(value || '', { emitUpdate: false });
        }
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <div class="rich-editor">
        <div class="rich-toolbar" aria-label="Description formatting">
            <button
                type="button"
                :class="{ active: editor?.isActive('heading', { level: 2 }) }"
                aria-label="Heading"
                @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
            >
                <Heading2 :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('bold') }"
                aria-label="Bold"
                @click="editor?.chain().focus().toggleBold().run()"
            >
                <Bold :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('italic') }"
                aria-label="Italic"
                @click="editor?.chain().focus().toggleItalic().run()"
            >
                <Italic :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('underline') }"
                aria-label="Underline"
                @click="editor?.chain().focus().toggleUnderline().run()"
            >
                <UnderlineIcon :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('strike') }"
                aria-label="Strikethrough"
                @click="editor?.chain().focus().toggleStrike().run()"
            >
                <Strikethrough :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('highlight') }"
                aria-label="Highlight"
                @click="editor?.chain().focus().toggleMark('highlight').run()"
            >
                <Highlighter :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('bulletList') }"
                aria-label="Bullet list"
                @click="editor?.chain().focus().toggleBulletList().run()"
            >
                <List :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('orderedList') }"
                aria-label="Numbered list"
                @click="editor?.chain().focus().toggleOrderedList().run()"
            >
                <ListOrdered :size="16" aria-hidden="true" />
            </button>
            <button
                type="button"
                :class="{ active: editor?.isActive('blockquote') }"
                aria-label="Quote"
                @click="editor?.chain().focus().toggleBlockquote().run()"
            >
                <Quote :size="16" aria-hidden="true" />
            </button>
            <span></span>
            <button type="button" aria-label="Undo" @click="editor?.chain().focus().undo().run()">
                <Undo2 :size="16" aria-hidden="true" />
            </button>
            <button type="button" aria-label="Redo" @click="editor?.chain().focus().redo().run()">
                <Redo2 :size="16" aria-hidden="true" />
            </button>
        </div>
        <EditorContent :editor="editor" />
        <p v-if="placeholder && !modelValue" class="rich-placeholder">{{ placeholder }}</p>
    </div>
</template>
