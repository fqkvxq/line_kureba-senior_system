export declare function runPreset(presetName?: string, customOptions?: Record<string, any>): Promise<{
    success: boolean;
    richMenuId?: string;
    preset?: string;
    imagePath?: string;
    generated?: any;
    error?: any;
}>;
