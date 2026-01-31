import { useState, useEffect, useRef } from 'react';
import { User, Mail, Save, Shield, Calendar, CheckCircle, AlertCircle, Lock, Phone, FileText, Camera, Trash2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import DashboardLayout from '../components/DashboardLayout';
import profileService from '../services/profileService';

const ProfilePage = () => {
    const navigate = useNavigate();
    const fileInputRef = useRef(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [uploadingImage, setUploadingImage] = useState(false);
    const [profile, setProfile] = useState(null);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);
    
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [contact, setContact] = useState('');
    const [bio, setBio] = useState('');
    const [dob, setDob] = useState('');
    const [gender, setGender] = useState('');

    useEffect(() => {
        fetchProfile();
    }, []);

    const fetchProfile = async () => {
        try {
            setLoading(true);
            const response = await profileService.getProfile();
            if (response.success) {
                setProfile(response.data);
                setName(response.data.name || '');
                setEmail(response.data.email || '');
                setContact(response.data.contact || '');
                setBio(response.data.bio || '');
                setDob(response.data.dob || '');
                setGender(response.data.gender || '');
            }
        } catch (err) {
            setError('Failed to load profile');
        } finally {
            setLoading(false);
        }
    };

    const handleUpdateProfile = async (e) => {
        e.preventDefault();
        setError(null);
        setSuccess(null);
        
        try {
            setSaving(true);
            const response = await profileService.updateProfile({ 
                name, 
                email, 
                contact: contact || null,
                bio: bio || null,
                dob: dob || null,
                gender: gender || null,
            });
            if (response.success) {
                setSuccess('Profile updated successfully');
                setProfile(prev => ({ ...prev, name, email, contact, bio, dob, gender }));
                // Update localStorage user data
                const userData = JSON.parse(localStorage.getItem('user') || '{}');
                userData.name = name;
                userData.email = email;
                localStorage.setItem('user', JSON.stringify(userData));
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to update profile');
        } finally {
            setSaving(false);
        }
    };

    const handleImageUpload = async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            setError('Image size must be less than 2MB');
            return;
        }

        try {
            setUploadingImage(true);
            setError(null);
            const response = await profileService.uploadImage(file);
            if (response.success) {
                setProfile(prev => ({ ...prev, profile_image: response.data.profile_image }));
                setSuccess('Profile image updated successfully');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to upload image');
        } finally {
            setUploadingImage(false);
        }
    };

    const handleRemoveImage = async () => {
        if (!confirm('Are you sure you want to remove your profile image?')) return;

        try {
            setUploadingImage(true);
            setError(null);
            const response = await profileService.removeImage();
            if (response.success) {
                setProfile(prev => ({ ...prev, profile_image: null }));
                setSuccess('Profile image removed');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to remove image');
        } finally {
            setUploadingImage(false);
        }
    };

    if (loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-[400px]">
                    <div className="text-gray-600">Loading profile...</div>
                </div>
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout>
            <div className="max-w-4xl mx-auto space-y-6">
                {/* Header with Profile Image */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div className="flex flex-col sm:flex-row items-center gap-6">
                        {/* Profile Image */}
                        <div className="relative">
                            <div className="w-24 h-24 rounded-full overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                                {profile?.profile_image ? (
                                    <img 
                                        src={profile.profile_image} 
                                        alt={profile.name} 
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <span className="text-white font-bold text-3xl">
                                        {profile?.name?.charAt(0)?.toUpperCase() || 'U'}
                                    </span>
                                )}
                            </div>
                            <input
                                type="file"
                                ref={fileInputRef}
                                onChange={handleImageUpload}
                                accept="image/jpeg,image/png,image/jpg,image/gif"
                                className="hidden"
                            />
                            <div className="absolute -bottom-1 -right-1 flex gap-1">
                                <button
                                    onClick={() => fileInputRef.current?.click()}
                                    disabled={uploadingImage}
                                    className="p-1.5 bg-blue-600 text-white rounded-full hover:bg-blue-700 disabled:opacity-50 shadow-lg"
                                    title="Upload image"
                                >
                                    <Camera className="w-3.5 h-3.5" />
                                </button>
                                {profile?.profile_image && (
                                    <button
                                        onClick={handleRemoveImage}
                                        disabled={uploadingImage}
                                        className="p-1.5 bg-red-600 text-white rounded-full hover:bg-red-700 disabled:opacity-50 shadow-lg"
                                        title="Remove image"
                                    >
                                        <Trash2 className="w-3.5 h-3.5" />
                                    </button>
                                )}
                            </div>
                        </div>
                        
                        {/* User Info */}
                        <div className="text-center sm:text-left">
                            <h2 className="text-2xl font-bold text-gray-800">{profile?.name}</h2>
                            <p className="text-gray-600">{profile?.email}</p>
                            {profile?.role && (
                                <span className="inline-flex items-center gap-1 mt-2 px-3 py-1 bg-blue-100 text-blue-700 text-xs rounded-full">
                                    <Shield className="w-3 h-3" />
                                    {profile.role.display_name || profile.role.name}
                                </span>
                            )}
                        </div>
                    </div>
                    {uploadingImage && (
                        <div className="mt-4 text-center text-sm text-blue-600">Uploading image...</div>
                    )}
                </div>

                {/* Alerts */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4 flex items-center gap-2">
                        <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0" />
                        <span className="text-red-700">{error}</span>
                    </div>
                )}
                {success && (
                    <div className="bg-green-50 border border-green-200 rounded-lg p-4 flex items-center gap-2">
                        <CheckCircle className="w-5 h-5 text-green-600 flex-shrink-0" />
                        <span className="text-green-700">{success}</span>
                    </div>
                )}

                {/* Profile Form */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 className="text-lg font-semibold text-gray-800 mb-6 flex items-center gap-2">
                        <User className="w-5 h-5 text-blue-600" />
                        Profile Information
                    </h3>
                    <form onSubmit={handleUpdateProfile} className="space-y-5">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                            {/* Name */}
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                                <div className="relative">
                                    <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                    <input
                                        type="text"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>
                            </div>

                            {/* Email */}
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                                <div className="relative">
                                    <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                    <input
                                        type="email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    />
                                </div>
                            </div>

                            {/* Contact */}
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                                <div className="relative">
                                    <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                    <input
                                        type="tel"
                                        value={contact}
                                        onChange={(e) => setContact(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="+91 9876543210"
                                    />
                                </div>
                            </div>

                            {/* Date of Birth */}
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                                <div className="relative">
                                    <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                                    <input
                                        type="date"
                                        value={dob}
                                        onChange={(e) => setDob(e.target.value)}
                                        max={new Date().toISOString().split('T')[0]}
                                        className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    />
                                </div>
                            </div>

                            {/* Gender */}
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                                <select
                                    value={gender}
                                    onChange={(e) => setGender(e.target.value)}
                                    className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        {/* Bio */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                            <div className="relative">
                                <FileText className="absolute left-3 top-3 w-4 h-4 text-gray-400" />
                                <textarea
                                    value={bio}
                                    onChange={(e) => setBio(e.target.value)}
                                    rows={3}
                                    maxLength={500}
                                    className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none"
                                    placeholder="Tell us about yourself..."
                                />
                            </div>
                            <p className="text-xs text-gray-500 mt-1">{bio.length}/500 characters</p>
                        </div>

                        {/* Submit Button */}
                        <div className="pt-2">
                            <button
                                type="submit"
                                disabled={saving}
                                className="w-full sm:w-auto flex items-center justify-center gap-2 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 font-medium"
                            >
                                <Save className="w-4 h-4" />
                                {saving ? 'Saving...' : 'Save Changes'}
                            </button>
                        </div>
                    </form>
                </div>

                {/* Account Details & Security */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Account Info */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <Shield className="w-5 h-5 text-blue-600" />
                            Account Details
                        </h3>
                        <div className="space-y-3 text-sm">
                            <div className="flex items-center justify-between py-2 border-b border-gray-100">
                                <span className="text-gray-600">Member Since</span>
                                <span className="font-medium text-gray-800">{profile?.created_at}</span>
                            </div>
                            <div className="flex items-center justify-between py-2 border-b border-gray-100">
                                <span className="text-gray-600">Account Status</span>
                                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                                    profile?.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                                }`}>
                                    {profile?.is_active ? 'Active' : 'Inactive'}
                                </span>
                            </div>
                            <div className="flex items-center justify-between py-2">
                                <span className="text-gray-600">Role</span>
                                <span className="font-medium text-gray-800 capitalize">
                                    {profile?.role?.display_name || profile?.role?.name || 'N/A'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Security */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                            <Lock className="w-5 h-5 text-blue-600" />
                            Security
                        </h3>
                        <p className="text-sm text-gray-600 mb-4">
                            Keep your account secure by using a strong password.
                        </p>
                        <button
                            onClick={() => navigate('/change-password')}
                            className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-medium"
                        >
                            <Lock className="w-4 h-4" />
                            Change Password
                        </button>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
};

export default ProfilePage;
