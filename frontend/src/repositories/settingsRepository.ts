export const settingsRepository = {
  getTheme: () => localStorage.getItem('theme') || 'light',
  setTheme: (val: string) => localStorage.setItem('theme', val),
};
