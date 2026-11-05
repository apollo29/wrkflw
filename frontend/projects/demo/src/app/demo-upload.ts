import type { WfUploadFile, WfUploadImage } from '@apollo29/workflow-client';

/** Demo-Upload: nimmt PDF bis 5 MB an (wie die Prüfung `pdf_max_5mb`), sonst Ablehnung. */
export const demoUpload: WfUploadFile = async (file, { handler }) => {
  await new Promise((resolve) => setTimeout(resolve, 150));
  const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
  if (handler === 'pdf_max_5mb' && (!isPdf || file.size > 5 * 1024 * 1024)) {
    return {
      rejected: `«${file.name}» wurde abgelehnt: Akzeptiert werden nur PDF-Dateien bis 5 MB.`,
    };
  }
  return { value: `datei-${file.name}` };
};

/** Demo-Bildupload: ohne Server gibt es nur eine feste Grafik aus `public/` zurück. */
export const demoUploadImage: WfUploadImage = async () => {
  await new Promise((resolve) => setTimeout(resolve, 150));
  return '/demo-bild.png';
};
