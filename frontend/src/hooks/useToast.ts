import { useState, useCallback } from 'react';
// TYPE-ONLY import: ToastContext.tsx imports this hook at runtime, so a value
// import here would close a real cycle in the module graph
// (ToastContext -> useToast -> ToastContext). `import type` is erased before
// the bundler ever sees it, which keeps the graph acyclic by construction
// instead of relying on esbuild happening to drop the unused binding.
import type { ToastType, ToastMessage } from '../context/ToastContext';

export function useToast() {
  const [toasts, setToasts] = useState<ToastMessage[]>([]);

  const showToast = useCallback((message: string, type: ToastType) => {
    const id = Math.random().toString(36).substring(2, 9);
    setToasts((prev) => [...prev, { id, message, type }]);
  }, []);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((toast) => toast.id !== id));
  }, []);

  return { toasts, showToast, removeToast };
}
