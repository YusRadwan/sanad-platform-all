/**
 * SANAD design system — shared UI primitives.
 *
 * Import from this barrel:  `import { Button, Card, Badge } from '../UI';`
 *
 * Design tokens (colours, radii, elevation, type conventions) live in
 * `src/index.css` under the `@theme` block. Nothing here invents a colour;
 * every value traces back to a token or to the existing Tailwind palette that
 * the app already used.
 */

export { cn } from './cn';
export { CONTROL_SIZES, controlBase } from './controlStyles';
export type { ControlSize } from './controlStyles';

export { default as Button } from './Button';
export type { ButtonProps, ButtonVariant, ButtonSize } from './Button';

export { Card, CardHeader, CardFooter } from './Card';
export type { CardProps, CardHeaderProps, CardFooterProps } from './Card';

export { Badge, statusTone } from './Badge';
export type { BadgeProps, BadgeTone, BadgeSize } from './Badge';

export {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  TableSkeleton,
  TablePagination,
} from './Table';
export type {
  TableProps,
  TableHeaderProps,
  TableBodyProps,
  TableRowProps,
  TableHeadProps,
  TableCellProps,
  TableEmptyStateProps,
  TableSkeletonProps,
} from './Table';

export { Pagination } from './Pagination';
export type { PaginationProps } from './Pagination';

export { default as Input } from './Input';
export type { InputProps } from './Input';

export { default as Select } from './Select';
export type { SelectProps } from './Select';

export { default as Textarea } from './Textarea';
export type { TextareaProps } from './Textarea';

export { default as Checkbox } from './Checkbox';
export type { CheckboxProps } from './Checkbox';

export { default as Radio, RadioGroup } from './Radio';
export type { RadioProps, RadioGroupProps } from './Radio';

export { FormField } from './FormField';
export type { FormFieldProps } from './FormField';

export { default as SearchBox } from './SearchBox';
export type { SearchBoxProps } from './SearchBox';

export { Dialog } from './Dialog';
export type { DialogProps, DialogSize } from './Dialog';

export { ConfirmDialog } from './ConfirmDialog';
export type { ConfirmDialogProps } from './ConfirmDialog';

export { EmptyState } from './EmptyState';
export type { EmptyStateProps } from './EmptyState';

export { Skeleton, SkeletonText } from './Skeleton';
export type { SkeletonProps, SkeletonTextProps } from './Skeleton';

export { PageHeader, SectionHeader } from './PageHeader';
export type { PageHeaderProps, SectionHeaderProps, BreadcrumbItem } from './PageHeader';

export { Toolbar } from './Toolbar';
export type { ToolbarProps } from './Toolbar';

export { Tabs } from './Tabs';
export type { TabsProps, TabItem } from './Tabs';
