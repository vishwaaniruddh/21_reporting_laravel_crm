import api from './api';

const profileService = {
    /**
     * Get current user profile
     */
    getProfile: async () => {
        const response = await api.get('/profile');
        return response.data;
    },

    /**
     * Update profile information
     */
    updateProfile: async (data) => {
        const response = await api.put('/profile', data);
        return response.data;
    },

    /**
     * Upload profile image
     */
    uploadImage: async (file) => {
        const formData = new FormData();
        formData.append('image', file);
        const response = await api.post('/profile/image', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        return response.data;
    },

    /**
     * Remove profile image
     */
    removeImage: async () => {
        const response = await api.delete('/profile/image');
        return response.data;
    },

    /**
     * Change password
     */
    changePassword: async (data) => {
        const response = await api.put('/profile/password', data);
        return response.data;
    },
};

export default profileService;
